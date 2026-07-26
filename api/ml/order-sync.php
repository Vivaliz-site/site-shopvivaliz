<?php
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once dirname(__DIR__, 2) . '/includes/ml-event-tracker.php';

function ml_order_dir(): string {
    $dir = ml_root() . '/storage/orders';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function ml_map_status(string $mlStatus): string {
    return match ($mlStatus) {
        'paid', 'confirmed' => 'pending_confirmation',
        'cancelled' => 'cancelled',
        default => 'pending_confirmation',
    };
}

function ml_track_purchase_events(array &$record, string $orderNumber): void {
    if (($record['source'] ?? '') !== 'mercado_livre') {
        return;
    }

    $mlStatus = strtolower(trim((string)($record['ml_status'] ?? '')));
    if (!in_array($mlStatus, ['paid', 'confirmed'], true)) {
        return;
    }

    if (!empty($record['ml_purchase_tracked_at'])) {
        return;
    }

    $trackedAny = false;
    foreach (is_array($record['items'] ?? null) ? $record['items'] : [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $productId = (string)($item['olist_product_id'] ?? $item['sku'] ?? '');
        if ($productId === '') {
            continue;
        }
        if (svml_track_event('purchase', $productId, [
            'source' => 'mercado_livre_order_sync',
            'order_number' => $orderNumber,
            'quantity' => (int)($item['quantity'] ?? 1),
            'unit_price' => (float)($item['price'] ?? 0),
        ])) {
            $trackedAny = true;
        }
    }

    if ($trackedAny) {
        $record['ml_purchase_tracked_at'] = gmdate('c');
    }
}

function ml_sync_order_from_webhook(string $resource): void {
    $order = ml_http_get('https://api.mercadolibre.com' . $resource);
    $mlOrderId = (string)($order['id'] ?? '');
    if ($mlOrderId === '') {
        throw new RuntimeException('ML order sem id no resource ' . $resource);
    }

    $orderNumber = 'ML' . $mlOrderId;
    $dir = ml_order_dir();
    $path = $dir . '/' . $orderNumber . '.json';

    $buyer = $order['buyer'] ?? [];
    $items = array_map(static function (array $it): array {
        $item = $it['item'] ?? [];
        return [
            'sku' => (string)($item['seller_sku'] ?? $item['id'] ?? ''),
            'name' => (string)($item['title'] ?? ''),
            'quantity' => (int)($it['quantity'] ?? 1),
            'price' => (float)($it['unit_price'] ?? 0),
        ];
    }, is_array($order['order_items'] ?? null) ? $order['order_items'] : []);

    if (is_file($path)) {
        $existing = json_decode((string)file_get_contents($path), true) ?: [];
        $existing['status'] = ml_map_status((string)($order['status'] ?? ''));
        $existing['ml_status'] = (string)($order['status'] ?? '');
        if (!isset($existing['items']) || !is_array($existing['items']) || $existing['items'] === []) {
            $existing['items'] = $items;
        }
        ml_track_purchase_events($existing, $orderNumber);
        file_put_contents($path, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        return;
    }

    $record = [
        'order_number' => $orderNumber,
        'status' => ml_map_status((string)($order['status'] ?? '')),
        'ml_status' => (string)($order['status'] ?? ''),
        'customer' => [
            'name' => (string)($buyer['nickname'] ?? ''),
            'email' => (string)($buyer['email'] ?? ''),
            'phone' => '',
            'cep' => '',
            'address' => '',
        ],
        'items' => $items,
        'items_total' => (float)($order['total_amount'] ?? 0),
        'shipping_total' => 0.0,
        'total' => (float)($order['total_amount'] ?? 0),
        'payment_method' => 'mercado_pago',
        'created_at' => (string)($order['date_created'] ?? date('c')),
        'source' => 'mercado_livre',
    ];

    ml_track_purchase_events($record, $orderNumber);
    file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}
