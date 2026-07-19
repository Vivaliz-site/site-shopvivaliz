<?php
/**
 * Validar Credenciais Gemini API
 * Testa autenticação e conectividade com Google Gemini
 */

declare(strict_types=1);

echo "🔐 Validando Gemini API Credentials\n";
echo "=================================\n\n";

// 1. Carregar chave de ambiente
$geminiApiKey = getenv('GEMINI_API_KEY');

if (!$geminiApiKey) {
    echo "❌ GEMINI_API_KEY não encontrada\n";
    echo "   Verifique GitHub Secrets > GEMINI_API_KEY\n";
    exit(1);
}

echo "✅ API Key encontrada: " . substr($geminiApiKey, 0, 20) . "...\n\n";

// 2. Testar conectividade
echo "🚀 Testando conectividade com Gemini API...\n";

$geminiModel = getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash';
$urlModel = rawurlencode($geminiModel);
$url = "https://generativelanguage.googleapis.com/v1beta/models/{$urlModel}:generateContent";
$url .= "?key=" . urlencode($geminiApiKey);

$payload = json_encode([
    'contents' => [
        [
            'parts' => [
                [
                    'text' => 'Test message from ShopVivaliz validation'
                ]
            ]
        ]
    ]
]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

curl_close($ch);

echo "📊 HTTP Status: $httpCode\n\n";

if ($httpCode === 200) {
    echo "✅ [SUCESSO] Gemini API autenticada!\n\n";
    
    $data = json_decode($response, true);
    
    if (isset($data['candidates']) && count($data['candidates']) > 0) {
        echo "📝 Resposta Recebida:\n";
        echo "   Modelo: {$geminiModel}\n";
        echo "   Status: ✅ Funcionando\n";
        echo "   Tokens: ~5-10 (teste)\n";
    }
    
    echo "\n════════════════════════════════════════\n";
    echo "✅ VALIDAÇÃO GEMINI PASSOU\n";
    echo "════════════════════════════════════════\n";
    exit(0);
    
} elseif ($httpCode === 401) {
    echo "❌ [ERRO 401] Credenciais Inválidas\n";
    echo "   Verifique a GEMINI_API_KEY\n";
    exit(1);
    
} elseif ($httpCode === 403) {
    echo "⚠️  [ERRO 403] Acesso Proibido\n";
    echo "   API pode estar desabilitada\n";
    echo "   Verifique em: console.cloud.google.com\n";
    exit(1);
    
} elseif ($httpCode === 429) {
    echo "⚠️  [ERRO 429] Rate Limit Excedido\n";
    echo "   Muitas requisições - aguarde alguns minutos\n";
    exit(1);
    
} else {
    echo "❌ [ERRO $httpCode] Resposta inesperada\n";
    echo "Resposta: " . substr($response, 0, 200) . "\n";
    if ($error) {
        echo "cURL Error: $error\n";
    }
    exit(1);
}
