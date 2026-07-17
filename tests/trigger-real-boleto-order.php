<?php
/**
 * Script to trigger a real Boleto purchase through local APIs.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Access denied. CLI only.\n";
    exit(1);
}

require_once __DIR__ . '/../config/bootstrap-env.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/product-price-enrich.php';
require_once __DIR__ . '/../includes/order-authoritative.php';

echo "🚀 Triggering Real Boleto Checkout Flow...\n";

// 1. Prepare parameters
$sku = 'Parafuso5x16';
$price = 0.01;
$qty = 1;
$shippingCep = '01310100';
$shippingTotal = 12.50;
$serviceId = 'sedex';
$expiresAt = time() + 3600;

// Resolve product
$resolved = svoa_resolve_items([['sku' => $sku, 'quantity' => $qty, 'price' => $price]]);
if ($resolved['errors'] !== []) {
    die("❌ Error resolving product: " . json_encode($resolved['errors']));
}

$fingerprintItems = array_map(static fn(array $item): array => [
    'sku' => (string)$item['sku'],
    'quantity' => (int)$item['quantity'],
    'price' => round((float)$item['price'], 2),
], $resolved['items']);

// Sign Quote Signature
$secret = getenv('QUOTE_SIGNING_KEY') ?: getenv('APP_KEY') ?: getenv('SHOPVIVALIZ_APP_KEY') ?: '';
if ($secret === '') {
    die("❌ QUOTE_SIGNING_KEY or APP_KEY missing from environment.\n");
}

$fingerprint = ['cep' => $shippingCep, 'items' => $fingerprintItems, 'service_id' => $serviceId, 'price' => $shippingTotal, 'expires_at' => $expiresAt];
$quoteId = hash_hmac('sha256', json_encode($fingerprint, JSON_UNESCAPED_SLASHES), $secret);

// Build Order Request Body
$orderPayload = [
    'items' => [
        [
            'sku' => $sku,
            'quantity' => $qty,
            'price' => $price,
            'name' => 'Parafuso 5x16'
        ]
    ],
    'cep' => $shippingCep,
    'shipping_total' => $shippingTotal,
    'shipping_service' => $serviceId,
    'shipping_label' => 'Entrega Rápida Sedex',
    'shipping_quote_id' => $quoteId,
    'shipping_expires_at' => $expiresAt,
    'payment_method' => 'boleto',
    'customer_name' => 'Frederico Mourao',
    'customer_email' => 'shopvivaliz@gmail.com',
    'customer_phone' => '11999999999',
    'cpf' => '52998224725', // Valid CPF test format
    'address' => 'Avenida Paulista, 1000',
    'street_name' => 'Avenida Paulista',
    'street_number' => '1000',
    'neighborhood' => 'Bela Vista',
    'city' => 'São Paulo',
    'state' => 'SP',
];

// Helper for Curl requests
function postJsonLocal(string $path, array $data): array {
    $url = 'https://dev.shopvivaliz.com.br' . $path;
    $ch = curl_init($url);
    $payload = json_encode($data);
    
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
    ]);
    // Skip SSL verification for local dev hostname if self-signed
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        return ['success' => false, 'code' => $httpCode, 'error' => $error];
    }
    
    $decoded = json_decode($response, true);
    return ['success' => $httpCode >= 200 && $httpCode < 300, 'code' => $httpCode, 'data' => $decoded, 'raw' => $response];
}

// Step 1: Create and validate order
echo "Step 1: Creating order via API...\n";
$createRes = postJsonLocal('/api/orders/create-validated.php', $orderPayload);

if (!$createRes['success']) {
    die("❌ Step 1 Failed (HTTP {$createRes['code']}): " . json_encode($createRes['data'] ?? $createRes['error']) . "\nRaw: " . $createRes['raw'] . "\n");
}

$orderNumber = $createRes['data']['order_number'] ?? '';
$sessionToken = $createRes['data']['payment_session_token'] ?? '';
echo "✅ Order Created successfully: $orderNumber\n";
echo "Session Token: $sessionToken\n\n";

// Step 2: Request real Boleto from Mercado Pago API
echo "Step 2: Requesting real Boleto from Mercado Pago...\n";
$boletoPayload = [
    'order_number' => $orderNumber,
    'payment_session_token' => $sessionToken
];
$boletoRes = postJsonLocal('/api/mercadopago/create-boleto.php', $boletoPayload);

if (!$boletoRes['success']) {
    die("❌ Step 2 Failed (HTTP {$boletoRes['code']}): " . json_encode($boletoRes['data'] ?? $boletoRes['error']) . "\nRaw: " . $boletoRes['raw'] . "\n");
}

echo "✅ Real Boleto generated successfully by Mercado Pago!\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Order Number: " . $boletoRes['data']['order_number'] . "\n";
echo "Status: " . $boletoRes['data']['status'] . "\n";
echo "Digitable Line: " . $boletoRes['data']['digitable_line'] . "\n";
echo "Ticket URL: " . $boletoRes['data']['ticket_url'] . "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "\nCheck your email inbox shopvivaliz@gmail.com for the transaction receipt e-mail!\n";
