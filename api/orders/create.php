<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/autodev/core/event_collector.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function svo_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function svo_root(): string
{
    return dirname(__DIR__, 2);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svo_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 200000) {
    svo_json(413, ['ok' => false, 'error' => 'payload_too_large']);
}
$body = json_decode($raw, true);
if (!is_array($body)) {
    svo_json(400, ['ok' => false, 'error' => 'invalid_json']);
}

$name = trim((string)($body['customer_name'] ?? ''));
$email = trim((string)($body['customer_email'] ?? ''));
$phone = trim((string)($body['customer_phone'] ?? ''));
$cep = preg_replace('/\D+/', '', (string)($body['cep'] ?? ''));
$address = trim((string)($body['address'] ?? ''));
$notes = trim((string)($body['notes'] ?? ''));
$items = is_array($body['items'] ?? null) ? $body['items'] : [];

if (strlen($name) > 120 || strlen($email) > 160 || strlen($phone) > 40 || strlen($address) > 300 || strlen($notes) > 1000) {
    svo_json(422, ['ok' => false, 'error' => 'field_too_long']);
}

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '' || strlen($cep) !== 8 || $address === '' || !$items) {
    svo_json(422, ['ok' => false, 'error' => 'missing_required_fields']);
}

if (count($items) > 100) {
    svo_json(422, ['ok' => false, 'error' => 'too_many_items']);
}

$cleanItems = [];
$total = 0.0;
foreach ($items as $item) {
    if (!is_array($item)) continue;
    $sku = trim((string)($item['sku'] ?? ''));
    $itemName = trim((string)($item['name'] ?? $sku));
    $quantity = max(1, min(99, (int)($item['quantity'] ?? 1)));
    $price = max(0.0, (float)($item['price'] ?? 0));
    if (strlen($sku) > 80 || strlen($itemName) > 220) continue;
    if ($sku === '' || $itemName === '') continue;
    $total += $price * $quantity;
    $cleanItems[] = [
        'sku' => $sku,
        'name' => $itemName,
        'quantity' => $quantity,
        'price' => round($price, 2),
        'olist_product_id' => trim((string)($item['olist_product_id'] ?? '')),
    ];
}

if (!$cleanItems) {
    svo_json(422, ['ok' => false, 'error' => 'empty_items']);
}

$orderNumber = 'SV' . date('YmdHis') . random_int(100, 999);
$record = [
    'order_number' => $orderNumber,
    'status' => 'pending_confirmation',
    'customer' => [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'cep' => $cep,
        'address' => $address,
    ],
    'items' => $cleanItems,
    'total' => round($total, 2),
    'notes' => $notes,
    'created_at' => date('c'),
    'source' => 'site_checkout',
];

$dir = svo_root() . '/storage/orders';
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    svo_json(500, ['ok' => false, 'error' => 'order_storage_unavailable']);
}
$path = $dir . '/' . $orderNumber . '.json';
if (file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
    svo_json(500, ['ok' => false, 'error' => 'order_write_failed']);
}

autodev_track('order_complete', [
    'order_number' => $orderNumber,
    'total' => round($total, 2),
    'items_count' => count($cleanItems),
    'items' => array_map(static function (array $item): array {
        return [
            'sku' => $item['sku'],
            'quantity' => $item['quantity'],
            'price' => $item['price'],
        ];
    }, $cleanItems),
]);

svo_json(200, [
    'ok' => true,
    'order_number' => $orderNumber,
    'status' => 'pending_confirmation',
    'message' => 'Pedido registrado para confirmacao manual de frete e pagamento.',
]);
