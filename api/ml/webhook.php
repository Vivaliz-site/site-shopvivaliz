<?php
declare(strict_types=1);
require_once __DIR__ . '/client.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method !== 'POST') {
    echo json_encode(['ok' => true, 'message' => 'Webhook ML ativo. Use POST para notificações.']);
    exit;
}

$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true) ?? [];

$entry = [
    'received_at'    => gmdate('c'),
    'topic'          => trim($_GET['topic']          ?? ($body['topic'] ?? '')),
    'resource'       => trim($_GET['resource']       ?? ($body['resource'] ?? '')),
    'user_id'        => trim($_GET['user_id']        ?? (string)($body['user_id'] ?? '')),
    'application_id' => trim($_GET['application_id'] ?? (string)($body['application_id'] ?? '')),
    'body'           => $body,
];

ml_append_log('ml-webhooks.log', $entry);

// Reage a notificacoes de pedido, gravando no mesmo formato usado pelo
// checkout do site (storage/orders/*.json), sem derrubar o webhook em
// caso de erro -- ML reduz/desativa notificacoes apos varias respostas
// nao-2xx, entao sempre respondemos 200 e so logamos falhas internas.
if ($entry['topic'] === 'orders_v2' && $entry['resource'] !== '') {
    try {
        ml_sync_order_from_webhook($entry['resource']);
    } catch (Throwable $e) {
        ml_append_log('ml-webhook-errors.log', [
            'at' => gmdate('c'),
            'resource' => $entry['resource'],
            'error' => $e->getMessage(),
        ]);
    }
}

echo json_encode(['ok' => true]);

function ml_log_dir(): ?string {
    $dir = ml_root() . '/logs';
    return (is_dir($dir) && is_writable($dir)) ? $dir : null;
}

function ml_append_log(string $filename, array $payload): void {
    $dir = ml_log_dir();
    if ($dir === null) {
        return;
    }
    $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($line === false) {
        return;
    }
    @file_put_contents($dir . '/' . $filename, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function ml_order_dir(): string {
    $dir = ml_root() . '/storage/orders';
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('ML order storage unavailable');
    }
    return $dir;
}

function ml_map_status(string $mlStatus): string {
    return match ($mlStatus) {
        'paid', 'confirmed' => 'pending_confirmation',
        'cancelled' => 'cancelled',
        default => 'pending_confirmation',
    };
}

function ml_sync_order_from_webhook(string $resource): void {
    // $resource costuma vir como "/orders/2000003508...".
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
        // Ja existe (webhook duplicado ou atualizacao de status) -- so
        // atualiza status/ml_status, preserva o resto do registro.
        $existing = json_decode((string)file_get_contents($path), true) ?: [];
        $existing['status'] = ml_map_status((string)($order['status'] ?? ''));
        $existing['ml_status'] = (string)($order['status'] ?? '');
        ml_write_json_file($path, $existing);
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

    ml_write_json_file($path, $record);
}

function ml_write_json_file(string $path, array $payload): void {
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($encoded === false) {
        throw new RuntimeException('ML webhook JSON encode failed');
    }
    if (file_put_contents($path, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('ML webhook write failed');
    }
}
