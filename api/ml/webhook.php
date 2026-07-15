<?php
declare(strict_types=1);
require_once __DIR__ . '/client.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');

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

$logDir = ml_root() . '/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
file_put_contents($logDir . '/ml-webhooks.log', $line, FILE_APPEND | LOCK_EX);

// Reage a notificacoes de pedido, gravando no mesmo formato usado pelo
// checkout do site (storage/orders/*.json), sem derrubar o webhook em
// caso de erro -- ML reduz/desativa notificacoes apos varias respostas
// nao-2xx, entao sempre respondemos 200 e so logamos falhas internas.
if ($entry['topic'] === 'orders_v2' && $entry['resource'] !== '') {
    try {
        ml_sync_order_from_webhook($entry['resource']);
    } catch (Throwable $e) {
        file_put_contents(
            $logDir . '/ml-webhook-errors.log',
            json_encode(['at' => gmdate('c'), 'resource' => $entry['resource'], 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}

echo json_encode(['ok' => true]);

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

    file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}
