<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

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

function svsc_env_load(): void
{
    $path = svsc_root() . '/.env';
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function svsc_env(string ...$keys): string
{
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

function svsc_catalog_product(string $productId, string $sku): array
{
    $jsonPath = svsc_root() . '/api/catalog/fallback-products.json';
    if (!is_file($jsonPath) || !is_readable($jsonPath)) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents($jsonPath), true);
    if (!is_array($decoded)) {
        return [];
    }
    foreach ($decoded as $row) {
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

svsc_env_load();

$productId = preg_replace('/\D+/', '', (string)($_GET['product_id'] ?? ''));
$sku = trim((string)($_GET['sku'] ?? ''));
$cep = preg_replace('/\D+/', '', (string)($_GET['cep'] ?? ''));

if (strlen($cep) !== 8) {
    svsc_json(422, ['ok' => false, 'error' => 'invalid_cep']);
}

$product = svsc_catalog_product($productId, $sku);
if (!$product) {
    svsc_json(404, ['ok' => false, 'error' => 'product_not_found']);
}

$token = svsc_env('MELHORENVIO_ACCESS_TOKEN', 'SHOPVIVALIZ_MELHORENVIO_ACCESS_TOKEN');
$fromPostalCode = preg_replace('/\D+/', '', svsc_env('MELHORENVIO_FROM_POSTAL_CODE', 'SHOPVIVALIZ_FROM_POSTAL_CODE')) ?: '35500000';
$payload = [
    'from' => ['postal_code' => $fromPostalCode],
    'to' => ['postal_code' => $cep],
    'products' => [[
        'id' => (string)($product['id'] ?? $product['olist_product_id'] ?? $product['sku'] ?? 'produto'),
        'width' => 16,
        'height' => 16,
        'length' => 16,
        'weight' => 1,
        'insurance_value' => max(1, (float)($product['price'] ?? 0)),
        'quantity' => 1,
    ]],
    'options' => ['receipt' => false, 'own_hand' => false, 'collect' => false],
];

if ($token === '') {
    svsc_json(503, [
        'ok' => false,
        'error' => 'missing_access_token',
        'message' => 'Configure MELHORENVIO_ACCESS_TOKEN no servidor.',
        'product' => $product,
    ]);
}

$result = svsc_post_json('https://www.melhorenvio.com.br/api/v2/me/shipment/calculate', $payload, $token);
svsc_json($result['ok'] ? 200 : 502, [
    'ok' => $result['ok'],
    'provider' => 'melhorenvio',
    'product' => [
        'id' => (string)($product['id'] ?? ''),
        'sku' => (string)($product['sku'] ?? ''),
        'name' => (string)($product['name'] ?? ''),
    ],
    'cep' => $cep,
    'payload' => $payload,
    'result' => $result,
]);

