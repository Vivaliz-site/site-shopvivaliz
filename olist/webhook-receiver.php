<?php
declare(strict_types=1);

/**
 * Backward-compatible Olist notification URL.
 * Authenticated notifications refresh the product through the official ERP v3
 * path; this route may NEVER overwrite the cache from the legacy API or
 * launch a detached three-step sync pipeline.
 */
header_remove('X-Powered-By');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$root = dirname(__DIR__);
require_once $root . '/includes/runtime-env-reader.php';
require_once $root . '/includes/webhook-secret.php';
require_once $root . '/includes/olist-erp-webhook.php';

function svol_response(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svol_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
}
$raw = (string)(file_get_contents('php://input') ?: '');
if ($raw === '' || strlen($raw) > 200000) {
    svol_response($raw === '' ? 400 : 413, ['ok' => false, 'error' => 'payload_size_invalid']);
}

// Signed messages use HMAC; partners without signatures must supply the
// shared secret configured in the ERP notification URL or X-Webhook-Key.
$signature = trim((string)($_SERVER['HTTP_X_OLIST_SIGNATURE'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? ''));
if ($signature !== '') {
    $secret = svre_value('OLIST_WEBHOOK_SECRET', $root);
    $authorized = $secret !== '' && hash_equals(hash_hmac('sha256', $raw, $secret), $signature);
} else {
    $authorized = sv_webhook_secret_valid('olist');
}
if (!$authorized) {
    svol_response(401, ['ok' => false, 'error' => 'unauthorized']);
}

$data = json_decode($raw, true);
if (!is_array($data) || $data === []) {
    svol_response(400, ['ok' => false, 'error' => 'invalid_json']);
}
$event = strtolower(trim((string)($data['event'] ?? $data['tipo'] ?? $data['type'] ?? '')));
$shouldSync = in_array($event, [
    'produto.criado', 'produto.atualizado', 'produto.alterado',
    'preco.alterado', 'estoque.alterado', 'estoque', 'preco', 'produto',
], true) || str_contains($event, 'produto') || str_contains($event, 'estoque')
    || str_contains($event, 'preco');

if (!$shouldSync) {
    svol_response(200, ['ok' => true, 'status' => 'ignored', 'event' => $event]);
}
$item = is_array($data['dados'] ?? null) ? $data['dados']
    : (is_array($data['produto'] ?? null) ? $data['produto'] : $data);
$id = trim((string)($item['produto_id'] ?? $item['product_id'] ?? $item['idProduto'] ?? $item['id'] ?? ''));
$sku = trim((string)($item['sku'] ?? $item['codigo'] ?? $item['skuMapeamento'] ?? ''));
if ($id !== '' && !ctype_digit($id)) svol_response(422, ['ok' => false, 'error' => 'product_id_invalid']);
if ($sku === '' && $id === '') svol_response(422, ['ok' => false, 'error' => 'product_identifier_missing']);
if (strlen($sku) > 160) svol_response(422, ['ok' => false, 'error' => 'sku_invalid']);

try {
    $product = svoei_refresh_product_from_v3($root, $id, $sku);
    svoei_log($root, 'legacy_olist_notification_synced', [
        'tipo' => $event, 'sku' => (string)($product['sku'] ?? $sku),
        'product_id' => $id,
    ]);
    svol_response(200, [
        'ok' => true,
        'status' => 'synced',
        'event' => $event,
        'price_source' => 'olist_tiny_amazon',
        'price_list_id' => SV_AMAZON_PRICE_LIST_ID,
    ]);
} catch (Throwable $e) {
    error_log('[olist-notification] canonical ERP v3 refresh failed: ' . get_class($e));
    svol_response(503, ['ok' => false, 'error' => 'erp_catalog_refresh_failed']);
}
