<?php
declare(strict_types=1);

/**
 * Compatibility receiver for older Olist/Tiny price notifications.
 * NEVER accept a monetary amount from a webhook payload. The official
 * authenticated ERP v3 PDV table is the only commercial source of truth.
 *
 * The canonical new notification URL is /api/olist/webhook.php?key=...
 * This receiver remains solely to avoid silent legacy price corruption.
 */
header('Content-Type: application/json; charset=utf-8');
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
if (!sv_webhook_secret_valid('tiny-product-price')) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}
$raw = file_get_contents('php://input') ?: '';
if ($raw === '' || strlen($raw) > 2_000_000) {
    http_response_code($raw === '' ? 400 : 413);
    echo json_encode(['ok' => false, 'error' => $raw === '' ? 'empty_payload' : 'payload_too_large']);
    exit;
}
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}
$product = $payload['produto'] ?? $payload['product'] ?? $payload['dados'] ?? $payload;
if (!is_array($product)) $product = [];
$productId = trim((string)($product['idProduto'] ?? $product['id'] ?? ''));
if ($productId !== '' && !ctype_digit($productId)) $productId = '';
$sku = trim((string)($product['sku'] ?? $product['codigo'] ?? ''));
if ($productId === '' && $sku === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'product_identifier_missing']);
    exit;
}

try {
    $synced = svoei_refresh_price_from_v3($root, $productId, $sku);
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'status' => 'price_synced',
        'sku' => $synced['sku'],
        'price_source' => 'olist_pdv',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (RuntimeException $e) {
    error_log('[tiny-price-legacy] PDV sync rejected: ' . $e->getMessage());
    http_response_code($e->getMessage() === 'catalog_sku_quarantined' ? 409 : 503);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[tiny-price-legacy] unexpected failure: ' . get_class($e));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal_error']);
}
