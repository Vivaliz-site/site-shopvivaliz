<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

// Load .env file
$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && !str_starts_with($line, '#')) {
            [$key, $value] = explode('=', $line, 2);
            if (!getenv(trim($key))) {
                putenv(trim($key) . '=' . trim($value));
            }
        }
    }
}

require_once __DIR__ . '/../includes/mercadopago-gateway.php';

$accessToken = svmp_env('MERCADOPAGO_ACCESS_TOKEN');
$emailTo = getenv('ADMIN_EMAIL') ?: 'fredmourao@gmail.com';

if (!$accessToken) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing Mercado Pago credentials']);
    exit;
}

// Create payment preference for boleto
$preference = [
    'items' => [
        [
            'title' => 'Teste ShopVivaliz - Boleto',
            'description' => 'Pedido de teste para validação de integração',
            'quantity' => 1,
            'unit_price' => 99.90,
        ]
    ],
    'payer' => [
        'name' => 'Teste',
        'email' => $emailTo,
        'identification' => ['type' => 'CPF', 'number' => '12345678901'],
    ],
    'payment_methods' => [
        'excluded_payment_methods' => [
            ['id' => 'credit_card'],
            ['id' => 'debit_card'],
            ['id' => 'atm'],
            ['id' => 'prepaid_card'],
            ['id' => 'wallet_purchase'],
            ['id' => 'account_money'],
            ['id' => 'pec'],
        ],
        'installments' => 1,
    ],
    'notification_url' => (getenv('SITE_URL') ?: 'https://dev.shopvivaliz.com.br') . '/api/webhook-mercadopago.php',
    'external_reference' => 'TEST-' . time(),
    'auto_return' => 'approved',
];

$ch = curl_init('https://api.mercadopago.com/checkout/preferences');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($preference),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 201) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Failed to create preference', 'http' => $httpCode]);
    exit;
}

$data = json_decode($response, true);

if (!isset($data['id']) || !isset($data['init_point'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid response format']);
    exit;
}

// Send email with boleto link
$preferenceId = $data['id'];
$checkoutUrl = $data['init_point'];
$subject = 'Boleto de Teste - ShopVivaliz';
$body = <<<BODY
Olá Fredmourao,

Seu boleto de teste foi gerado com sucesso!

Preference ID: $preferenceId
Valor: R$ 99,90
URL do Checkout: $checkoutUrl

Faça o teste do pagamento clicando no link acima.

Obrigado,
Sistema ShopVivaliz
BODY;

$headers = "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "From: noreply@shopvivaliz.com.br\r\n";

mail($emailTo, $subject, $body, $headers);

echo json_encode([
    'ok' => true,
    'preference_id' => $preferenceId,
    'checkout_url' => $checkoutUrl,
    'email_sent' => true,
    'email_to' => $emailTo,
    'amount' => 99.90,
    'external_reference' => $preference['external_reference'],
]);
