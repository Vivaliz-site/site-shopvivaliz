<?php
/**
 * Gerar Token Direto via Client Credentials Flow
 * Sem precisar de interação do usuário
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// Carregar .env
$envFile = dirname(__DIR__) . '/.env';
$clientId = '';
$clientSecret = '';

if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, 'OLIST_CLIENT_ID=')) {
            $clientId = explode('=', $line, 2)[1] ?? '';
        } elseif (str_starts_with($line, 'OLIST_CLIENT_SECRET=')) {
            $clientSecret = explode('=', $line, 2)[1] ?? '';
        }
    }
}

if (!$clientId || !$clientSecret) {
    http_response_code(400);
    echo json_encode([
        'erro' => 'Credenciais não configuradas',
        'clientId' => $clientId ? 'OK' : 'FALTA',
        'clientSecret' => $clientSecret ? 'OK' : 'FALTA',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ============================================================
// CLIENT CREDENTIALS FLOW
// ============================================================

$tokenUrl = 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token';

$postData = http_build_query([
    'grant_type' => 'client_credentials',
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'scope' => 'openid',
]);

echo "═══════════════════════════════════════════════════════════\n";
echo "GERANDO TOKEN VIA CLIENT CREDENTIALS\n";
echo "═══════════════════════════════════════════════════════════\n\n";

echo "Client ID: " . substr($clientId, 0, 40) . "...\n";
echo "Endpoint: $tokenUrl\n";
echo "Grant Type: client_credentials\n\n";

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $postData,
        'timeout' => 30,
    ],
    'https' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $postData,
        'timeout' => 30,
    ]
]);

$response = @file_get_contents($tokenUrl, false, $context);

if (!$response) {
    http_response_code(500);
    echo json_encode([
        'erro' => 'Falha ao conectar com servidor OAuth',
        'endpoint' => $tokenUrl,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$tokenData = json_decode($response, true);

echo "Resposta OAuth:\n";
echo json_encode($tokenData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

if (!isset($tokenData['access_token'])) {
    http_response_code(401);
    echo json_encode([
        'erro' => 'Token não obtido',
        'detalhes' => $tokenData,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ============================================================
// SALVAR TOKEN EM .ENV
// ============================================================

$accessToken = $tokenData['access_token'];
$refreshToken = $tokenData['refresh_token'] ?? '';
$expiresIn = $tokenData['expires_in'] ?? 14400;

$envContent = file_get_contents($envFile);

$replacements = [
    'OLIST_ACCESS_TOKEN' => $accessToken,
    'OLIST_REFRESH_TOKEN' => $refreshToken,
    'TINY_ACCESS_TOKEN' => $accessToken,
    'TINY_REFRESH_TOKEN' => $refreshToken,
];

foreach ($replacements as $key => $value) {
    $pattern = '/^' . preg_quote($key, '/') . '=.*/m';
    if (preg_match($pattern, $envContent)) {
        $envContent = preg_replace($pattern, $key . '=' . $value, $envContent);
    } else {
        $envContent .= "\n$key=$value";
    }
}

file_put_contents($envFile, $envContent);

// ============================================================
// SUCESSO!
// ============================================================

http_response_code(200);
echo json_encode([
    'sucesso' => true,
    'mensagem' => 'Token gerado e salvo em .env!',
    'access_token_preview' => substr($accessToken, 0, 50) . '...',
    'expires_in_horas' => round($expiresIn / 3600, 1),
    'arquivo' => '.env',
    'proximo_passo' => 'Teste em: https://shopvivaliz.com.br/olist/test-token-v3.php',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
