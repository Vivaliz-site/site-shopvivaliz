<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(__DIR__, 2) . '/includes/product-price-enrich.php';
require_once dirname(__DIR__, 2) . '/includes/order-authoritative.php';
require_once dirname(__DIR__, 2) . '/includes/order-request-context.php';
require_once dirname(__DIR__, 2) . '/includes/order-idempotency.php';
require_once dirname(__DIR__, 2) . '/includes/order-rate-limit.php';

function svq_fail(int $status, string $error, string $message, array $extra = []): never {
    http_response_code($status);
    echo json_encode(array_merge(['ok'=>false,'error'=>$error,'message'=>$message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function svq_env(string ...$keys): string {
    svp_env_load();
    foreach ($keys as $key) {
        $value = getenv($key);
        if (is_string($value) && trim($value) !== '') return trim($value);
        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && trim($_ENV[$key]) !== '') return trim($_ENV[$key]);
    }
    return '';
}
function svq_secret(): string {
    $secret = svq_env('QUOTE_SIGNING_KEY','APP_KEY','SHOPVIVALIZ_APP_KEY','SHOPVIVALIZ_AGENT_KEY');
    if ($secret === '') svq_fail(503,'quote_signing_key_missing','A assinatura segura de frete não está configurada. Tente novamente em instantes.');
    return $secret;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') svq_fail(405,'method_not_allowed','Método não permitido.');
if (!svorl_allow()) svq_fail(429,'rate_limit_exceeded','Muitas tentativas de criação de pedido. Aguarde alguns minutos.');
$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 200000) svq_fail(413,'payload_too_large','O pedido excede o tamanho permitido.');
$body = json_decode($raw, true);
if (!is_array($body)) svq_fail(400,'invalid_json','Dados do pedido inválidos.');

$requestedItems = is_array($body['items'] ?? null) ? $body['items'] : [];
if ($requestedItems === []) svq_fail(422,'empty_items','O carrinho está vazio.');
$resolved = svoa_resolve_items($requestedItems);
if ($resolved['errors'] !== []) svq_fail(409,'order_items_invalid','Preço, estoque ou produto inválido.', ['items'=>$resolved['errors']]);

$authoritativeBySku = [];
foreach ($resolved['items'] as $item) $authoritativeBySku[strtolower((string)$item['sku'])] = $item;
foreach ($requestedItems as $item) {
    if (!is_array($item)) continue;
    $sku = strtolower(trim((string)($item['sku'] ?? '')));
    $server = $authoritativeBySku[$sku] ?? null;
    if (!is_array($server)) svq_fail(404,'product_not_found','Um produto não foi encontrado.');
    $clientPrice = round((float)($item['price'] ?? 0), 2);
    if (abs($clientPrice - (float)$server['price']) > 0.009) svq_fail(409,'item_price_mismatch','O preço de um item foi alterado. Atualize o carrinho.', ['sku'=>$server['sku']]);
}

$shippingTotal = round(max(0.0, (float)($body['shipping_total'] ?? 0)), 2);
$shippingCep = preg_replace('/\D+/', '', (string)($body['shipping_cep'] ?? $body['cep'] ?? ''));
$serviceId = trim((string)($body['shipping_service'] ?? ''));
$quoteId = trim((string)($body['shipping_quote_id'] ?? ''));
$expiresAt = (int)($body['shipping_expires_at'] ?? 0);
if (strlen($shippingCep) !== 8 || $shippingTotal <= 0 || $serviceId === '' || $quoteId === '' || $expiresAt <= 0) {
    svq_fail(422,'shipping_quote_required','Selecione uma cotação de frete válida antes de finalizar.');
}
if ($expiresAt < time()) svq_fail(409,'shipping_quote_expired','A cotação de frete expirou. Calcule novamente no carrinho.');

$fingerprintItems = array_map(static fn(array $item): array => [
    'sku' => (string)$item['sku'],
    'quantity' => (int)$item['quantity'],
    'price' => round((float)$item['price'], 2),
], $resolved['items']);
$fingerprint = ['cep'=>$shippingCep,'items'=>$fingerprintItems,'service_id'=>$serviceId,'price'=>$shippingTotal,'expires_at'=>$expiresAt];
$expected = hash_hmac('sha256', json_encode($fingerprint, JSON_UNESCAPED_SLASHES), svq_secret());
if (!hash_equals($expected, $quoteId)) svq_fail(409,'shipping_quote_invalid','O valor do frete foi alterado ou não corresponde à cotação. Calcule novamente.');

$idempotencyKey = svoi_key($body, $resolved['items']);
if (!svoi_claim($idempotencyKey)) svq_fail(409,'duplicate_order_request','Este pedido já está sendo processado ou foi enviado recentemente.');
svorc_set($body, $resolved['items']);

require __DIR__ . '/process-validated.php';
