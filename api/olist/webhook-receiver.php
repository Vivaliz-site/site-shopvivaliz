<?php
declare(strict_types=1);

/**
 * Compatibility endpoint for older Olist integrations.
 * Never trust submitted prices/stock and never launch a second daemon:
 * the authoritative Olist v3 Amazon-list refresh uses the catalog lock.
 */
header_remove('X-Powered-By');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$root = dirname(__DIR__, 2);
require_once $root . '/config/bootstrap-env.php';
require_once $root . '/includes/webhook-secret.php';
require_once $root . '/includes/olist-erp-webhook.php';

function svlo_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function svlo_header(string $name): string
{
    $server = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $value = trim((string)($_SERVER[$server] ?? ''));
    if ($value !== '') return $value;
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $headerValue) {
            if (strcasecmp((string)$key, $name) === 0) return trim((string)$headerValue);
        }
    }
    return '';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svlo_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

// Preserve the older X-Webhook-Token, but also accept the current official
// shared-secret URL/header. Never permit missing credentials in production.
$legacyExpected = trim((string)(getenv('OLIST_WEBHOOK_TOKEN') ?: getenv('ERP_WEBHOOK_TOKEN') ?: ($_ENV['OLIST_WEBHOOK_TOKEN'] ?? '')));
$legacyProvided = svlo_header('X-Webhook-Token');
if ($legacyProvided === '') {
    $authorization = svlo_header('Authorization');
    $legacyProvided = stripos($authorization, 'Bearer ') === 0
        ? trim(substr($authorization, 7))
        : trim((string)($_GET['token'] ?? ''));
}
$legacyValid = $legacyExpected !== '' && $legacyProvided !== ''
    && hash_equals($legacyExpected, $legacyProvided);
if (!$legacyValid && !sv_webhook_secret_valid('olist')) {
    svlo_response(401, ['ok' => false, 'error' => 'unauthorized']);
}

$raw = (string)(file_get_contents('php://input') ?: '');
if ($raw === '' || strlen($raw) > 200000) {
    svlo_response($raw === '' ? 400 : 413, ['ok' => false, 'error' => 'payload_size_invalid']);
}
$data = json_decode($raw, true);
if (!is_array($data)) svlo_response(400, ['ok' => false, 'error' => 'invalid_json']);

$event = strtolower(trim((string)($_GET['event'] ?? $data['event'] ?? $data['tipo'] ?? '')));
if ($event === 'order') {
    // This legacy path never applied order state; canonical order-status
    // processing stays on /api/olist/webhook.php.
    svlo_response(200, ['ok' => true, 'status' => 'ignored', 'event' => 'order']);
}
if (!in_array($event, ['price', 'stock', 'product'], true)) {
    svlo_response(422, ['ok' => false, 'error' => 'unsupported_event']);
}

$item = is_array($data['produto'] ?? null) ? $data['produto']
    : (is_array($data['dados'] ?? null) ? $data['dados'] : $data);
$sku = trim((string)($item['sku'] ?? $item['produto_sku'] ?? $item['codigo'] ?? $item['skuMapeamento'] ?? ''));
$productId = trim((string)($item['idProduto'] ?? $item['produto_id'] ?? $item['product_id'] ?? $item['id'] ?? ''));
if ($productId !== '' && !ctype_digit($productId)) svlo_response(422, ['ok' => false, 'error' => 'product_id_invalid']);
if ($sku === '' && $productId === '') svlo_response(422, ['ok' => false, 'error' => 'product_identifier_missing']);
if (strlen($sku) > 160) svlo_response(422, ['ok' => false, 'error' => 'sku_invalid']);

try {
    // The source of truth is always the Olist ERP Amazon price list, not
    // the webhook payload. This function writes under olist-erp-webhook.lock.
    $product = svoei_refresh_product_from_v3($root, '', $sku);
    svoei_log($root, 'legacy_webhook_catalog_synced', [
        'tipo' => $event, 'sku' => (string)($product['sku'] ?? $sku),
    ]);
    svlo_response(200, [
        'ok' => true,
        'status' => 'synced',
        'event' => $event,
        'sku' => (string)($product['sku'] ?? $sku),
        'price_source' => 'olist_tiny_amazon',
        'price_list_id' => SV_AMAZON_PRICE_LIST_ID,
    ]);
} catch (Throwable $e) {
    error_log('[legacy-olist-webhook] ERP v3 refresh failed: ' . get_class($e));
    svlo_response(503, ['ok' => false, 'error' => 'erp_catalog_refresh_failed']);
}
