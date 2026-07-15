<?php
/**
 * CRIAR PAYMENT REAL AGORA
 * Simula exatamente o que o checkout faria
 * Usa as credenciais de PRODUÇÃO do .env
 */

echo "════════════════════════════════════════════════════════════════════\n";
echo "🎯 CRIANDO PAYMENT REAL AGORA\n";
echo "════════════════════════════════════════════════════════════════════\n\n";

// Carregar credenciais
$env = [];
foreach (file('.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}

$token = $env['MERCADOPAGO_ACCESS_TOKEN'];

echo "✅ Credenciais carregadas\n";
echo "   Token: " . substr($token, 0, 30) . "...\n\n";

// Usar curl para criar payment (funciona sem SDK)
$payment_data = [
    "transaction_amount" => 76.00,
    "description" => "Rodízio 75mm - PAYMENT REAL",
    "payment_method_id" => "pix",
    "payer" => [
        "email" => "teste@shopvivaliz.com.br",
        "first_name" => "Cliente",
        "last_name" => "Teste",
        "identification" => [
            "type" => "CPF",
            "number" => "12345678901"
        ]
    ],
    "notification_url" => "https://dev.shopvivaliz.com.br/api/webhook-mercadopago.php"
];

$ch = curl_init('https://api.mercadopago.com/v1/payments');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payment_data),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ],
    CURLOPT_SSL_VERIFYPEER => false
]);

echo "📡 Enviando requisição para Mercado Pago...\n\n";

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true);

if ($http_code === 201 && isset($data['id'])) {
    $payment_id = $data['id'];
    $status = $data['status'];
    
    echo "════════════════════════════════════════════════════════════════════\n";
    echo "✅ PAYMENT REAL CRIADO COM SUCESSO!\n";
    echo "════════════════════════════════════════════════════════════════════\n\n";
    
    echo "📦 PAYMENT ID (USE NO MERCADO PAGO):\n";
    echo "   $payment_id\n\n";
    
    echo "📊 Detalhes:\n";
    echo "   Status: $status\n";
    echo "   Valor: R\$ 76.00\n";
    echo "   Método: PIX\n";
    echo "   Criado: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Salvar resultado
    file_put_contents('PAYMENT-ID-REAL-CRIADO.txt', 
        "✅ PAYMENT ID REAL\n\n$payment_id\n\nStatus: $status"
    );
    
    echo "════════════════════════════════════════════════════════════════════\n\n";
    
    echo "✅ Resultado salvo em: PAYMENT-ID-REAL-CRIADO.txt\n";
    
} else {
    echo "❌ Erro ao criar payment\n";
    echo "HTTP Code: $http_code\n";
    echo "Response: $response\n";
}
?>
