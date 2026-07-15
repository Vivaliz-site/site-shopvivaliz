<?php
// Teste local via SDK

require_once __DIR__ . '/vendor/autoload.php';

use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Order\OrderClient;

// Carregar .env
$env = [];
if (file_exists('.env')) {
    foreach (file('.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim(trim($value), "\"'");
    }
}

$token = $env['MERCADOPAGO_ACCESS_TOKEN'] ?? '';

if (!$token) {
    die("❌ Access token não configurado\n");
}

echo "✅ Token encontrado: " . substr($token, 0, 30) . "...\n\n";

MercadoPagoConfig::setAccessToken($token);

try {
    $client = new OrderClient();
    
    $payload = [
        "external_reference" => "PED-SDK-" . date('YmdHis'),
        "total_amount" => 76.00,
        "items" => [
            [
                "sku_number" => "RODIZIO-75MM",
                "title" => "Rodízio 75mm",
                "description" => "Rodízio profissional",
                "unit_price" => 76.00,
                "quantity" => 1,
                "category" => "Rodízios"
            ]
        ],
        "payer" => [
            "email" => "cliente@test.com",
            "first_name" => "Cliente",
            "last_name" => "Teste",
            "phone" => "(37) 99999-1234"
        ]
    ];
    
    echo "📡 Criando order via SDK...\n";
    echo "Payload:\n";
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    
    $order = $client->create($payload);
    
    echo "✅ ORDER CRIADA COM SUCESSO!\n";
    echo "\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "📦 ORDER ID (MERCADO PAGO): " . $order->id . "\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "\n";
    echo "📋 Detalhes:\n";
    echo "   External Reference: " . $payload['external_reference'] . "\n";
    echo "   Valor Total: R\$ " . $payload['total_amount'] . "\n";
    echo "   Status: " . $order->status . "\n";
    echo "   Cliente: " . $payload['payer']['email'] . "\n";
    echo "\n";
    echo "🔗 Validar em:\n";
    echo "   https://www.mercadopago.com.br/account/orders\n";
    echo "\n";
    
    // Salvar em arquivo para referência
    file_put_contents('LAST-ORDER-ID.txt', $order->id);
    echo "✅ Order ID salvo em LAST-ORDER-ID.txt\n";
    
} catch (Exception $e) {
    echo "❌ Erro ao criar order: " . $e->getMessage() . "\n";
    echo "\nResposta completa:\n";
    echo json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
