<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(__DIR__, 2) . '/includes/product-price-enrich.php';

function svq_fail(int $status, string $error, string $message): never {
    http_response_code($status);
    echo json_encode(['ok'=>false,'error'=>$error,'message'=>$message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
    return svq_env('APP_KEY','SHOPVIVALIZ_APP_KEY','QUOTE_SIGNING_KEY') ?: hash('sha256', dirname(__DIR__, 2) . '|shopvivaliz-shipping-v2');
}
function svq_catalog(): array {
    $path = dirname(__DIR__, 2) . '/api/catalog/fallback-products.json';
    $decoded = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    return is_array($decoded) ? $decoded : [];
}
function svq_catalog_map(): array {
    $map = [];
    foreach (svq_catalog() as $row) {
        if (!is_array($row)) continue;
        $sku = trim((string)($row['sku'] ?? ''));
        if ($sku !== '') $map[strtolower($sku)] = $row;
    }
    return $map;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') svq_fail(405,'method_not_allowed','Método não permitido.');
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) svq_fail(400,'invalid_json','Dados do pedido inválidos.');

$shippingTotal = round(max(0.0, (float)($body['shipping_total'] ?? 0)), 2);
$shippingCep = preg_replace('/\D+/', '', (string)($body['shipping_cep'] ?? $body['cep'] ?? ''));
$serviceId = trim((string)($body['shipping_service'] ?? ''));
$quoteId = trim((string)($body['shipping_quote_id'] ?? ''));
$expiresAt = (int)($body['shipping_expires_at'] ?? 0);

if ($shippingTotal > 0 || $serviceId !== '' || $quoteId !== '') {
    if (strlen($shippingCep) !== 8 || $serviceId === '' || $quoteId === '' || $expiresAt <= 0) {
        svq_fail(422,'invalid_shipping_quote','A cotação de frete está incompleta. Calcule novamente no carrinho.');
    }
    if ($expiresAt < time()) {
        svq_fail(409,'shipping_quote_expired','A cotação de frete expirou. Calcule novamente no carrinho.');
    }

    $catalog = svq_catalog_map();
    $fingerprintItems = [];
    foreach ((array)($body['items'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $sku = trim((string)($item['sku'] ?? ''));
        $row = $catalog[strtolower($sku)] ?? null;
        if (!is_array($row)) svq_fail(404,'product_not_found','Um produto do carrinho não foi encontrado para validar o frete.');
        $fingerprintItems[] = [
            'sku' => (string)($row['sku'] ?? $sku),
            'quantity' => max(1, min(99, (int)($item['quantity'] ?? 1))),
            'price' => round(max(1.0, (float)($row['price'] ?? 0)), 2),
        ];
    }
    if ($fingerprintItems === []) svq_fail(422,'empty_items','O carrinho está vazio.');

    $fingerprint = [
        'cep' => $shippingCep,
        'items' => $fingerprintItems,
        'service_id' => $serviceId,
        'price' => $shippingTotal,
        'expires_at' => $expiresAt,
    ];
    $expected = hash_hmac('sha256', json_encode($fingerprint, JSON_UNESCAPED_SLASHES), svq_secret());
    if (!hash_equals($expected, $quoteId)) {
        svq_fail(409,'shipping_quote_invalid','O valor do frete foi alterado ou não corresponde à cotação. Calcule novamente.');
    }
}

require __DIR__ . '/create.php';
