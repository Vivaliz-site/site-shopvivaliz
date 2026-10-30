<?php
/**
 * Legacy Tiny price webhook. Never trust its submitted price: use it only as
 * a notification to reload the exact SKU and the Amazon price list from ERP.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$root = dirname(__DIR__, 2);
require_once $root . '/includes/webhook-secret.php';
require_once $root . '/includes/olist-erp-webhook.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}
if (!sv_webhook_secret_gate('tiny-product-price')) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
if ($raw === '' || strlen($raw) > 200000) {
    http_response_code($raw === '' ? 400 : 413);
    echo json_encode(['ok' => false, 'error' => 'payload_size_invalid']);
    exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}
$item = is_array($data['produto'] ?? null) ? $data['produto'] :
    (is_array($data['product'] ?? null) ? $data['product'] : $data);
$sku = trim((string)($item['sku'] ?? $item['codigo'] ?? ''));
if ($sku === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'sku_missing']);
    exit;
}

try {
    $product = svoei_refresh_product_from_v3($root, '', $sku);
    svoei_log($root, 'amazon_price_synced', ['tipo' => 'precos', 'sku' => (string)($product['sku'] ?? $sku)]);
    echo json_encode([
        'ok' => true,
        'status' => 'updated',
        'sku' => (string)($product['sku'] ?? $sku),
        'price' => (float)($product['precos']['preco'] ?? 0),
        'price_source' => 'olist_tiny_amazon',
        'price_list_id' => SV_AMAZON_PRICE_LIST_ID,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'product_identifier_invalid']);
} catch (Throwable $e) {
    // Fail closed: the last verified price cache is left intact.
    svoei_log($root, 'amazon_price_sync_failed', ['tipo' => 'precos', 'sku' => $sku]);
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'erp_amazon_price_refresh_failed']);
}
