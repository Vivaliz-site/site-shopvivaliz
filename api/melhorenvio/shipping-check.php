<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(__DIR__, 2) . '/includes/product-price-enrich.php';
require_once dirname(__DIR__, 2) . '/includes/catalog-runtime.php';

function svsc_root(): string
{
    return dirname(__DIR__, 2);
}

function svsc_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function svsc_env(string ...$keys): string
{
    svp_env_load();
    foreach ($keys as $key) {
        $value = getenv($key);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && trim($_ENV[$key]) !== '') {
            return trim($_ENV[$key]);
        }
    }
    return '';
}

function svsc_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function svsc_catalog_product(string $productId, string $sku): array
{
    foreach (svcr_products() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowId = trim((string)($row['id'] ?? $row['olist_product_id'] ?? ''));
        $rowSku = trim((string)($row['sku'] ?? ''));
        if (($productId !== '' && $rowId === $productId) || ($sku !== '' && strcasecmp($rowSku, $sku) === 0)) {
            return $row;
        }
    }

    return [];
}

function svsc_product(string $productId, string $sku): array
{
    $catalog = svsc_catalog_product($productId, $sku);
    if ($catalog !== []) {
        return $catalog;
    }

    $db = svp_db();
    if ($db instanceof mysqli) {
        $row = svp_lookup_product($db, $sku, $productId);
        $db->close();
        if ($row !== []) {
            return $row;
        }
    }

    return [];
}

function svsc_item_product(array $item): array
{
    $productId = trim((string)($item['product_id'] ?? $item['id'] ?? $item['olist_product_id'] ?? ''));
    $sku = trim((string)($item['sku'] ?? ''));
    $product = svsc_product($productId, $sku);

    if ($product === []) {
        return [];
    }

    $quantity = max(1, (int)($item['quantity'] ?? 1));
    $insuranceValue = (float)($item['price'] ?? 0);
    if ($insuranceValue <= 0) {
        $insuranceValue = (float)($product['price'] ?? 0);
    }

    return [
        'id' => (string)($product['id'] ?? $product['olist_product_id'] ?? $product['sku'] ?? 'produto'),
        'sku' => (string)($product['sku'] ?? $sku),
        'name' => (string)($product['name'] ?? $item['name'] ?? $sku),
        'width' => max(1, (int)($item['width'] ?? 16)),
        'height' => max(1, (int)($item['height'] ?? 16)),
        'length' => max(1, (int)($item['length'] ?? 16)),
        'weight' => max(0.1, (float)($item['weight'] ?? 1)),
        'insurance_value' => max(1, $insuranceValue),
        'quantity' => $quantity,
    ];
}

function svsc_post_json(string $url, array $payload, string $token): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'error' => 'curl_missing'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'User-Agent: ShopVivaliz-ShippingCheck/1.0',
        ],
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'error' => $error ?: null,
        'body' => is_string($body) ? json_decode($body, true) ?? $body : null,
    ];
}

$body = svsc_body();
$cep = preg_replace('/\D+/', '', (string)($body['cep'] ?? $_GET['cep'] ?? ''));

if (strlen($cep) !== 8) {
    svsc_json(422, ['ok' => false, 'error' => 'invalid_cep']);
}

$items = is_array($body['items'] ?? null) ? $body['items'] : [];
if ($items === []) {
    $items = [[
        'product_id' => (string)($_GET['product_id'] ?? ''),
        'olist_product_id' => (string)($_GET['olist_product_id'] ?? ''),
        'sku' => (string)($_GET['sku'] ?? ''),
        'quantity' => (int)($_GET['quantity'] ?? 1),
        'price' => (float)($_GET['price'] ?? 0),
    ]];
}

$products = [];
foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }
    $product = svsc_item_product($item);
    if ($product === []) {
        svsc_json(404, ['ok' => false, 'error' => 'product_not_found', 'item' => $item]);
    }
    $products[] = [
        'id' => $product['id'],
        'sku' => $product['sku'],
        'name' => $product['name'],
        'width' => $product['width'],
        'height' => $product['height'],
        'length' => $product['length'],
        'weight' => $product['weight'],
        'insurance_value' => $product['insurance_value'],
        'quantity' => $product['quantity'],
    ];
}

require_once dirname(__DIR__, 2) . '/includes/melhorenvio-oauth.php';
$token = me_current_access_token() ?: svsc_env(
    'MELHORENVIO_ACCESS_TOKEN',
    'SHOPVIVALIZ_MELHORENVIO_ACCESS_TOKEN',
    'MELHORENVIO_API_KEY',
    'SHOPVIVALIZ_MELHORENVIO_API_KEY'
);
$fromPostalCode = preg_replace('/\D+/', '', svsc_env('MELHORENVIO_FROM_POSTAL_CODE', 'SHOPVIVALIZ_FROM_POSTAL_CODE')) ?: '35501236';
$payload = [
    'from' => ['postal_code' => $fromPostalCode],
    'to' => ['postal_code' => $cep],
    'products' => $products,
    'options' => ['receipt' => false, 'own_hand' => false, 'collect' => false],
];

if ($token === '') {
    svsc_json(503, [
        'ok' => false,
        'error' => 'missing_access_token',
        'message' => 'Configure MELHORENVIO_ACCESS_TOKEN no servidor.',
        'products' => $products,
    ]);
}

$result = svsc_post_json(me_api_base() . '/api/v2/me/shipment/calculate', $payload, $token);
$options = [];
foreach ((array)($result['body'] ?? []) as $option) {
    if (!is_array($option) || !empty($option['error'])) {
        continue;
    }
    $price = (float)($option['price'] ?? 0);
    if ($price <= 0) {
        continue;
    }
    $options[] = [
        'id' => (string)($option['id'] ?? ''),
        'name' => (string)($option['name'] ?? $option['company']['name'] ?? 'Frete'),
        'company' => (string)($option['company']['name'] ?? ''),
        'price' => $price,
        'delivery_time' => (int)($option['delivery_time'] ?? 0),
    ];
}

$cheapest = null;
foreach ($options as $option) {
    if ($cheapest === null || $option['price'] < $cheapest['price']) {
        $cheapest = $option;
    }
}

svsc_json($result['ok'] ? 200 : 502, [
    'ok' => $result['ok'],
    'provider' => 'melhorenvio',
    'products' => array_map(static function (array $product): array {
        return [
            'id' => $product['id'],
            'sku' => $product['sku'],
            'name' => $product['name'],
            'quantity' => $product['quantity'],
            'insurance_value' => $product['insurance_value'],
        ];
    }, $products),
    'cep' => $cep,
    'payload' => $payload,
    'shipping_options' => $options,
    'shipping_total' => $cheapest['price'] ?? null,
    'selected_option' => $cheapest,
    'result' => $result,
]);
