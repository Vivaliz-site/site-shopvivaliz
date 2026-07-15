<?php
/**
 * CRIAR PAGAMENTO REAL EM PRODUÇÃO
 * Usa as credenciais configuradas no .env
 */

require_once __DIR__ . '/vendor/autoload.php';

use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Order\OrderClient;

// Carregar .env
$env = [];
if (file_exists('.env')) {
    foreach (file('.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim(trim($v), "\"'");
    }
}

$accessToken = $env['MERCADOPAGO_ACCESS_TOKEN'] ?? null;
$publicKey = $env['MERCADOPAGO_PUBLIC_KEY'] ?? null;

echo "════════════════════════════════════════════════════════════════════\n";
echo "🔴 CRIANDO PAGAMENTO REAL EM PRODUÇÃO\n";
echo "════════════════════════════════════════════════════════════════════\n\n";

if (!$accessToken) {
    echo "❌ Access Token não configurado!\n";
    exit(1);
}

echo "✅ Token encontrado (produção)\n";
echo "   " . substr($accessToken, 0, 20) . "...\n\n";

// Configurar SDK com credenciais de produção
MercadoPagoConfig::setAccessToken($accessToken);

try {
    // PASSO 1: Criar Order
    echo "PASSO 1: Criando Order no Mercado Pago...\n";
    
    $orderClient = new OrderClient();
    $externalRef = "PED-PROD-" . date('YmdHis') . "-" . rand(1000, 9999);
    
    $orderData = [
        "external_reference" => $externalRef,
        "total_amount" => 76.00,
        "items" => [
            [
                "sku_number" => "RODIZIO-75MM",
                "title" => "Rodízio 75mm",
                "unit_price" => 76.00,
                "quantity" => 1
            ]
        ],
        "payer" => [
            "email" => "teste@shopvivaliz.com.br",
            "first_name" => "Cliente",
            "last_name" => "Real"
        ]
    ];
    
    $order = $orderClient->create($orderData);
    
    if (!$order || !$order->id) {
        throw new Exception("Order não criada");
    }
    
    echo "✅ Order criada: " . $order->id . "\n\n";
    
    // PASSO 2: Criar Payment (PIX)
    echo "PASSO 2: Criando Payment (PIX)...\n";
    
    $paymentClient = new PaymentClient();
    $paymentData = [
        "transaction_amount" => 76.00,
        "description" => "Rodízio 75mm - REAL",
        "payment_method_id" => "pix",
        "external_reference" => $externalRef,
        "payer" => [
            "email" => "teste@shopvivaliz.com.br",
            "identification" => [
                "type" => "CPF",
                "number" => "12345678901"
            ]
        ]
    ];
    
    $payment = $paymentClient->create($paymentData);
    
    if (!$payment || !$payment->id) {
        throw new Exception("Payment não criado");
    }
    
    $paymentId = $payment->id;
    $status = $payment->status;
    
    echo "✅ Payment criado: $paymentId\n";
    echo "✅ Status: $status\n\n";
    
    // PASSO 3: Exibir resultado
    echo "════════════════════════════════════════════════════════════════════\n";
    echo "🎉 PAGAMENTO REAL CRIADO COM SUCESSO!\n";
    echo "════════════════════════════════════════════════════════════════════\n\n";
    
    echo "📦 ORDER ID (Use para validar Mercado Pago):\n";
    echo "   $paymentId\n\n";
    
    echo "📋 Detalhes:\n";
    echo "   External Reference: $externalRef\n";
    echo "   Valor: R\$ 76.00\n";
    echo "   Método: PIX\n";
    echo "   Status: $status\n";
    echo "   Criado: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Salvar em arquivo
    $result = "✅ PAGAMENTO REAL CRIADO\n\n";
    $result .= "PAYMENT ID:\n";
    $result .= "$paymentId\n\n";
    $result .= "Status: $status\n";
    $result .= "External Ref: $externalRef\n";
    $result .= "Valor: R\$ 76.00\n\n";
    $result .= "Use este Payment ID no painel Mercado Pago!\n";
    
    file_put_contents('PAYMENT-ID-PRODUCAO.txt', $result);
    
    echo "✅ Resultado salvo em: PAYMENT-ID-PRODUCAO.txt\n";
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    exit(1);
}
?>
