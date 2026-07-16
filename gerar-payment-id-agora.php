<?php
/**
 * Gerar Payment ID Real via Mercado Pago API (com curl)
 * Sem dependências do SDK - usa curl direto
 */

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

if (!$accessToken) {
    die("❌ ERRO: MERCADOPAGO_ACCESS_TOKEN não configurado no .env\n");
}

// Dados do pagamento de teste
$paymentData = [
    'transaction_amount' => 10.00,
    'description' => 'Pagamento de Teste ShopVivaliz',
    'payment_method_id' => 'bolbradesco', // Boleto Bradesco
    'payer' => [
        'email' => 'teste@shopvivaliz.com.br',
        'first_name' => 'Cliente',
        'last_name' => 'Teste',
        'identification' => [
            'type' => 'CPF',
            'number' => '12345678909'
        ]
    ],
    'external_reference' => 'TESTE-' . time(),
    'statement_descriptor' => 'SHOPVIVALIZ'
];

echo "🔄 Criando pagamento de teste via API Mercado Pago...\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://api.mercadopago.com/v1/payments',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'X-Idempotency-Key: ' . uniqid('MP-', true)
    ],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($paymentData),
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    die("❌ ERRO CURL: $curlError\n");
}

$result = json_decode($response, true);

if ($httpCode === 201 && isset($result['id'])) {
    echo "\n✅ PAGAMENTO CRIADO COM SUCESSO!\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "💳 PAYMENT ID GERADO:\n";
    echo "   " . $result['id'] . "\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "\n📋 DETALHES:\n";
    echo "   Status: " . ($result['status'] ?? 'N/A') . "\n";
    echo "   Valor: R$ " . ($result['transaction_amount'] ?? 'N/A') . "\n";
    echo "   Método: " . ($result['payment_method_id'] ?? 'N/A') . "\n";
    echo "   Referência: " . ($result['external_reference'] ?? 'N/A') . "\n";

    if (isset($result['transaction_details']['external_resource_url'])) {
        echo "   Boleto: " . $result['transaction_details']['external_resource_url'] . "\n";
    }

    echo "\n🎯 COMO USAR:\n";
    echo "   1. Copie o ID: " . $result['id'] . "\n";
    echo "   2. Acesse Mercado Pago Developers\n";
    echo "   3. Cole no campo 'Order ID'\n";
    echo "   4. Clique 'Avaliar'\n";
    echo "   ✅ Integração validada!\n";

    // Salvar para referência
    file_put_contents(__DIR__ . '/PAYMENT_ID_PRONTO.txt', $result['id']);
    echo "\n✅ ID salvo em: PAYMENT_ID_PRONTO.txt\n";

} else {
    echo "❌ ERRO (HTTP $httpCode):\n";
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}
?>
