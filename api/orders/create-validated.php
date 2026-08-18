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
require_once dirname(__DIR__, 2) . '/includes/coupons.php';
require_once dirname(__DIR__, 2) . '/includes/buy-together.php';
require_once dirname(__DIR__, 2) . '/includes/inventory-reservations.php';

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

$buyTogether = svbt_validate_offer(null, $resolved['items']);
$body['buy_together'] = $buyTogether;

$itemsSubtotal = array_reduce(
    $resolved['items'],
    static fn(float $sum, array $item): float => $sum + ((float)$item['price'] * (int)$item['quantity']),
    0.0
);
$promotionalSubtotal = max(0.0, round($itemsSubtotal - (float)$buyTogether['amount'], 2));

// Um pedido aceita exatamente zero ou um cupom. Estruturas de lista e
// separadores usuais de múltiplos códigos são recusados de forma explícita.
if (is_array($body['coupon_code'] ?? null)) {
    svq_fail(422, 'multiple_coupons_not_allowed', 'É permitido aplicar apenas 1 cupom por pedido.');
}
$couponCode = strtoupper(trim((string)($body['coupon_code'] ?? '')));
if ($couponCode !== '' && preg_match('/[,;\s]/', $couponCode) === 1) {
    svq_fail(422, 'multiple_coupons_not_allowed', 'É permitido aplicar apenas 1 cupom por pedido.');
}
if ($couponCode !== '') {
    $customerEmail = strtolower(trim((string)($body['customer_email'] ?? '')));
    $coupon = svcp_validate($couponCode, $promotionalSubtotal, $customerEmail);
    if (!$coupon['ok']) {
        $message = $coupon['error'] === 'coupon_customer_mismatch'
            ? 'Este cupom é pessoal e está vinculado a outro cliente.'
            : 'Cupom inválido ou não aplicável a este carrinho.';
        svq_fail(422, $coupon['error'] ?: 'coupon_invalid', $message);
    }
    $body['coupon'] = $coupon;
}

$idempotencyKey = svoi_key($body, $resolved['items']);
if (!svoi_claim($idempotencyKey)) svq_fail(409,'duplicate_order_request','Este pedido já está sendo processado ou foi enviado recentemente.');

$reservationKey = hash('sha256', $idempotencyKey);
try {
    svir_reserve($reservationKey, $resolved['items'], (string)($body['payment_method'] ?? 'pix'));
} catch (SvirInsufficientStock $error) {
    svoi_release($idempotencyKey);
    svq_fail(409, 'insufficient_stock', 'O estoque mudou enquanto você finalizava. Atualize o carrinho.', [
        'sku' => $error->sku,
        'available' => $error->available,
        'requested' => $error->requested,
    ]);
} catch (Throwable $error) {
    svoi_release($idempotencyKey);
    error_log('[OrderValidated] inventory reservation failed: ' . $error->getMessage());
    svq_fail(503, 'inventory_reservation_unavailable', 'Não foi possível reservar o estoque com segurança. Tente novamente.');
}

svir_register_response_finalizer(
    $reservationKey,
    strtolower(trim((string)($body['customer_email'] ?? '')))
);
svorc_set($body, $resolved['items']);

require __DIR__ . '/process-validated.php';
