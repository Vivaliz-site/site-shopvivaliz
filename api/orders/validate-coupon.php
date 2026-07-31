<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(__DIR__, 2) . '/includes/order-authoritative.php';
require_once dirname(__DIR__, 2) . '/includes/coupons.php';

function svvc_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svvc_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 50000) {
    svvc_json(413, ['ok' => false, 'error' => 'payload_too_large']);
}
$body = json_decode($raw, true);
if (!is_array($body)) {
    svvc_json(400, ['ok' => false, 'error' => 'invalid_json']);
}

$code = trim((string)($body['coupon_code'] ?? ''));
$items = is_array($body['items'] ?? null) ? $body['items'] : [];
if ($code === '') {
    svvc_json(422, ['ok' => false, 'error' => 'coupon_empty']);
}

$resolved = svoa_resolve_items($items);
$itemsSubtotal = array_reduce($resolved['items'], static fn(float $sum, array $item): float => $sum + $item['price'] * $item['quantity'], 0.0);

$result = svcp_validate($code, $itemsSubtotal);
if (!$result['ok']) {
    svvc_json(422, ['ok' => false, 'error' => $result['error']]);
}

svvc_json(200, [
    'ok' => true,
    'code' => $result['code'],
    'percent' => $result['percent'],
    'amount' => $result['amount'],
    'label' => $result['label'],
]);
