<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

// Load runtime secrets
$runtimeSecretsFile = __DIR__ . '/../config/runtime-secrets.php';
if (is_file($runtimeSecretsFile)) {
    $secrets = require $runtimeSecretsFile;
    if (is_array($secrets)) {
        foreach ($secrets as $k => $v) {
            if (!getenv($k)) putenv($k . '=' . (string)$v);
        }
    }
}

require_once __DIR__ . '/../includes/mercadopago-gateway.php';

$accessToken = svmp_env('MERCADOPAGO_ACCESS_TOKEN');
$publicKey = svmp_env('MERCADOPAGO_PUBLIC_KEY');

if (!$accessToken || !$publicKey) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing Mercado Pago credentials']);
    exit;
}

// Create test order
$testOrder = [
    'title' => 'Teste ShopVivaliz',
    'description' => 'Pedido de teste para validação de integração',
    'quantity' => 1,
    'unit_price' => 99.90,
    'currency_id' => 'BRL',
];

$ch = curl_init('https://api.mercadopago.com/checkout/orders');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($testOrder),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 201) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Failed to create order', 'http' => $httpCode, 'response' => $response]);
    exit;
}

$data = json_decode($response, true);

if (!isset($data['id'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid response format', 'data' => $data]);
    exit;
}

// Return success with Order ID
echo json_encode([
    'ok' => true,
    'order_id' => $data['id'],
    'public_key' => $publicKey,
    'checkout_url' => $data['url'] ?? null,
    'amount' => 99.90,
    'currency' => 'BRL',
    'timestamp' => time(),
]);
