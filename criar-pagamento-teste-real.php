<?php
/**
 * Criar Pagamento Real de Teste no Mercado Pago
 * Este script gera um Payment ID válido que pode ser usado para validar a integração
 *
 * Uso:
 * php criar-pagamento-teste-real.php
 *
 * Saída: Payment ID válido pronto para usar no painel do Mercado Pago
 */

declare(strict_types=1);

// Autoload Composer
require_once __DIR__ . '/vendor/autoload.php';

use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;

// Load .env
$env = [];
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim(trim($value), "\"'");
    }
}

$accessToken = $env['MERCADOPAGO_ACCESS_TOKEN'] ?? '';
$publicKey = $env['MERCADOPAGO_PUBLIC_KEY'] ?? '';

if (!$accessToken) {
    die("❌ ERRO: MERCADOPAGO_ACCESS_TOKEN não configurado no .env\n");
}

// Configurar SDK
MercadoPagoConfig::setAccessToken($accessToken);

try {
    // Criar um pagamento de teste com boleto (mais simples para teste)
    $client = new PaymentClient();

    $paymentData = [
        'transaction_amount' => 10.00, // R$10 para teste
        'description' => 'Pagamento de Teste ShopVivaliz',
        'payment_method_id' => 'bolbradesco', // Boleto Bradesco - vai gerar boleto de teste
        'payer' => [
            'email' => 'teste@shopvivaliz.com.br',
            'first_name' => 'Cliente',
            'last_name' => 'Teste',
            'identification' => [
                'type' => 'CPF',
                'number' => '12345678909' // CPF de teste do Mercado Pago
            ]
        ],
        'external_reference' => 'TESTE-' . time(),
        'statement_descriptor' => 'SHOPVIVALIZ TESTE'
    ];

    echo "🔄 Criando pagamento de teste...\n";
    echo "   Método: Boleto Bradesco\n";
    echo "   Valor: R$ 10.00\n";

    $payment = $client->create($paymentData);

    if ($payment && isset($payment->id)) {
        echo "\n✅ SUCESSO!\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "📦 PAYMENT ID GERADO:\n";
        echo "   " . $payment->id . "\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "\n📋 DETALHES:\n";
        echo "   Status: " . ($payment->status ?? 'desconhecido') . "\n";
        echo "   Valor: R$ " . ($payment->transaction_amount ?? 'N/A') . "\n";
        echo "   Referência: " . ($payment->external_reference ?? 'N/A') . "\n";
        echo "   Boleto URL: " . ($payment->transaction_details->external_resource_url ?? 'N/A') . "\n";

        echo "\n🎯 PRÓXIMO PASSO:\n";
        echo "   1. Copie o Payment ID: " . $payment->id . "\n";
        echo "   2. Acesse: https://www.mercadopago.com.br/developers/pt\n";
        echo "   3. Cole no campo 'Order ID' no painel de testes\n";
        echo "   4. Clique 'Avaliar Qualidade'\n";
        echo "   5. Sua integração estará validada!\n";

        // Salvar Payment ID em arquivo para referência
        file_put_contents(__DIR__ . '/PAYMENT_ID_TESTE.txt', $payment->id);
        echo "\n✅ Payment ID salvo em: PAYMENT_ID_TESTE.txt\n";

    } else {
        echo "❌ ERRO: Não foi possível criar o pagamento\n";
        echo json_encode($payment, JSON_PRETTY_PRINT) . "\n";
    }

} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
?>
