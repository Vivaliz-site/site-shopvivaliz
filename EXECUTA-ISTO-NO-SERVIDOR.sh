#!/bin/bash
# Execute no servidor: ssh -i chave.pem ubuntu@137.131.156.17

cd /home/ubuntu/site-shopvivaliz

# Criar pagamento REAL usando as credenciais do servidor
php << 'PHPEND'
<?php
require_once 'vendor/autoload.php';
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Payment\PaymentClient;

// Carregar .env
$env = parse_ini_file('.env');
MercadoPagoConfig::setAccessToken($env['MERCADOPAGO_ACCESS_TOKEN']);

try {
    $client = new PaymentClient();
    $payment = $client->create([
        "transaction_amount" => 76.00,
        "description" => "Rodízio 75mm - Real",
        "payment_method_id" => "pix",
        "payer" => [
            "email" => "teste@real.com.br",
            "identification" => ["type" => "CPF", "number" => "12345678901"]
        ]
    ]);
    
    echo "SUCESSO!\n";
    echo "PAYMENT_ID=" . $payment->id . "\n";
    echo "STATUS=" . $payment->status . "\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}
?>
PHPEND
