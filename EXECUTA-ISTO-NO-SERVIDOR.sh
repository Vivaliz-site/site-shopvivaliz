#!/bin/bash
# Execute somente na VM com papel SITE (alias recomendado: shopvivaliz-a1-site).
# ATENCAO: este utilitario cria pagamento real. Nao faz parte de smoke tests automatizados.

set -euo pipefail
cd /home/ubuntu/site-shopvivaliz

if [[ "${ALLOW_REAL_PAYMENT_TEST:-}" != "YES" ]]; then
    echo "BLOQUEADO: defina ALLOW_REAL_PAYMENT_TEST=YES somente para teste real explicitamente autorizado." >&2
    exit 2
fi

php << 'PHPEND'
<?php
require_once 'vendor/autoload.php';
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Payment\PaymentClient;

$env = parse_ini_file('.env');
MercadoPagoConfig::setAccessToken($env['MERCADOPAGO_ACCESS_TOKEN']);

try {
    $client = new PaymentClient();
    $payment = $client->create([
        "transaction_amount" => 76.00,
        "description" => "Rodizio 75mm - Real",
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
    exit(1);
}
?>
PHPEND
