<?php
/**
 * Gerador de Token Tiny/Olist OAuth
 * Localização: https://shopvivaliz.com.br/gen-token.php
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$baseDir = __DIR__;
$envFile = $baseDir . '/.env';

// ========== CARREGAR .ENV ==========
$clientId = '';
$clientSecret = '';

if (!is_file($envFile)) {
    http_response_code(500);
    echo json_encode(['erro' => '.env não encontrado']);
    exit;
}

foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with($line, 'OLIST_CLIENT_ID=')) {
        $clientId = trim(explode('=', $line, 2)[1] ?? '');
    } elseif (str_starts_with($line, 'OLIST_CLIENT_SECRET=')) {
        $clientSecret = trim(explode('=', $line, 2)[1] ?? '');
    }
}

if (!$clientId || !$clientSecret) {
    http_response_code(400);
    echo json_encode([
        'erro' => 'Credenciais faltando',
        'clientId_ok' => !empty($clientId),
        'clientSecret_ok' => !empty($clientSecret),
    ]);
    exit;
}

// ========== CLIENT CREDENTIALS FLOW ==========
$tokenUrl = 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token';

$postData = http_build_query([
    'grant_type' => 'client_credentials',
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'scope' => 'openid',
]);

$context = stream_context_create([
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
    echo json_encode(['erro' => 'Falha ao conectar com OAuth']);
    exit;
}

$tokenData = json_decode($response, true);

if (!isset($tokenData['access_token'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'Token não obtido', 'resposta' => $tokenData]);
    exit;
}

// ========== SALVAR EM .ENV ==========
$accessToken = $tokenData['access_token'];
$refreshToken = $tokenData['refresh_token'] ?? '';

$envContent = file_get_contents($envFile);

$keys = ['OLIST_ACCESS_TOKEN', 'OLIST_REFRESH_TOKEN', 'TINY_ACCESS_TOKEN', 'TINY_REFRESH_TOKEN'];
$vals = [$accessToken, $refreshToken, $accessToken, $refreshToken];

for ($i = 0; $i < count($keys); $i++) {
    $key = $keys[$i];
    $val = $vals[$i];
    $pattern = '/^' . preg_quote($key, '/') . '=.*/m';
    if (preg_match($pattern, $envContent)) {
        $envContent = preg_replace($pattern, "$key=$val", $envContent);
    } else {
        $envContent .= "\n$key=$val";
    }
}

file_put_contents($envFile, $envContent);

// ========== SUCESSO ==========
http_response_code(200);
echo json_encode([
    'sucesso' => true,
    'token' => substr($accessToken, 0, 50) . '...',
    'expires' => round(($tokenData['expires_in'] ?? 14400) / 3600, 1) . 'h',
    'arquivo' => '.env',
]);
?>
