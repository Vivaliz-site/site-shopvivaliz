<?php
require_once __DIR__ . '/vendor/autoload.php';
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Payment\PaymentClient;

// Carregar credenciais do .env
$env = [];
foreach (file('.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}

$token = $env['MERCADOPAGO_ACCESS_TOKEN'];
MercadoPagoConfig::setAccessToken($token);

try {
    $client = new PaymentClient();
    $payment = $client->create([
        "transaction_amount" => 76.00,
        "description" => "Rodízio 75mm - ShopVivaliz REAL",
        "payment_method_id" => "pix",
        "payer" => [
            "email" => "teste@shopvivaliz.com.br",
            "first_name" => "Teste",
            "last_name" => "Real",
            "identification" => ["type" => "CPF", "number" => "12345678901"]
        ]
    ]);
    
    echo "✅ PAGAMENTO CRIADO!\n";
    echo "PAYMENT ID: " . $payment->id . "\n";
    echo "STATUS: " . $payment->status . "\n";
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage();
}
