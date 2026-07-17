<?php
/**
 * OAuth Callback - Troca código por token e salva em .env
 * Documentação: https://api-docs.erp.olist.com/documentacao/comecando/autenticacao
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

// ============================================================
// PASSO 1: Verificar erro ou código
// ============================================================

$error = $_GET['error'] ?? null;
$code = $_GET['code'] ?? null;

if ($error) {
    http_response_code(400);
    echo json_encode([
        'erro' => 'Autorização negada',
        'detalhes' => $_GET['error_description'] ?? $error,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if (!$code) {
    http_response_code(400);
    echo json_encode([
        'erro' => 'Código não recebido',
        'acao' => 'Clique no link de login novamente',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ============================================================
// PASSO 2: Trocar código por token
// ============================================================

$redirectUri = 'https://dev.shopvivaliz.com.br/olist/callback.php';
$tokenUrl = 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token';

$postData = http_build_query([
    'grant_type' => 'authorization_code',
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'code' => $code,
    'redirect_uri' => $redirectUri,
]);

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

if (!isset($tokenData['access_token'])) {
    http_response_code(401);
    echo json_encode([
        'erro' => 'Falha ao trocar codigo por token',
        'detalhes_oauth' => $tokenData,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ============================================================
// PASSO 3: Salvar tokens em .env
// ============================================================

$accessToken = $tokenData['access_token'];
$refreshToken = $tokenData['refresh_token'] ?? '';
$expiresIn = $tokenData['expires_in'] ?? 14400;

$envContent = is_file($envFile) ? file_get_contents($envFile) : '';

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

$written = file_put_contents($envFile, $envContent);

if ($written === false) {
    http_response_code(500);
    echo json_encode([
        'erro' => 'Token obtido do Tiny mas falha ao gravar em .env (verifique permissao de escrita para o usuario do PHP)',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ============================================================
// SUCESSO!
// ============================================================

http_response_code(200);
echo json_encode([
    'sucesso' => true,
    'mensagem' => 'Token V3 obtido e salvo com sucesso!',
    'access_token' => substr($accessToken, 0, 50) . '...',
    'expires_in_horas' => round($expiresIn / 3600, 1),
    'arquivo_atualizado' => '.env',
    'proximo_passo' => 'Acesse https://dev.shopvivaliz.com.br/olist/test-token-v3.php para testar',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
