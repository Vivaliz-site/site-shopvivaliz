<?php
/**
 * Renovador de Token Tiny/Olist
 * Usa refresh_token para obter novo access_token
 * Executa: php olist/token-refresh.php
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$envFile = dirname(__DIR__) . '/.env';
$logFile = dirname(__DIR__) . '/logs/olist-token-refresh.log';

function svtr_log(string $msg): void
{
    global $logFile;
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

if (!is_file($envFile)) {
    svtr_log('ERRO: .env não encontrado');
    http_response_code(500);
    echo json_encode(['erro' => '.env não encontrado']);
    exit;
}

// Carregar .env
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with($line, 'OLIST_CLIENT_ID=')) {
        $env['OLIST_CLIENT_ID'] = explode('=', $line, 2)[1] ?? '';
    } elseif (str_starts_with($line, 'OLIST_CLIENT_SECRET=')) {
        $env['OLIST_CLIENT_SECRET'] = explode('=', $line, 2)[1] ?? '';
    } elseif (str_starts_with($line, 'OLIST_REFRESH_TOKEN=')) {
        $env['OLIST_REFRESH_TOKEN'] = explode('=', $line, 2)[1] ?? '';
    }
}

$clientId = trim($env['OLIST_CLIENT_ID'] ?? '');
$clientSecret = trim($env['OLIST_CLIENT_SECRET'] ?? '');
$refreshToken = trim($env['OLIST_REFRESH_TOKEN'] ?? '');

if (!$refreshToken) {
    svtr_log('ERRO: refresh_token não configurado');
    http_response_code(400);
    echo json_encode(['erro' => 'refresh_token não configurado']);
    exit;
}

// ============================================================
// REFRESH TOKEN FLOW
// ============================================================

$tokenUrl = 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token';

$postData = http_build_query([
    'grant_type' => 'refresh_token',
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'refresh_token' => $refreshToken,
]);

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $postData,
        'timeout' => 30,
        'ignore_errors' => true,
    ],
    'https' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $postData,
        'timeout' => 30,
        'ignore_errors' => true,
    ],
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
    echo json_encode(['erro' => 'Token não renovado', 'resposta' => $tokenData]);
    exit;
}

// ============================================================
// SALVAR NOVO TOKEN
// ============================================================

$accessToken = $tokenData['access_token'];
$newRefreshToken = $tokenData['refresh_token'] ?? $refreshToken;

$envContent = file_get_contents($envFile);

$keys = ['OLIST_ACCESS_TOKEN', 'OLIST_REFRESH_TOKEN', 'TINY_ACCESS_TOKEN', 'TINY_REFRESH_TOKEN'];
$values = [$accessToken, $newRefreshToken, $accessToken, $newRefreshToken];

for ($i = 0; $i < count($keys); $i++) {
    $key = $keys[$i];
    $val = $values[$i];
    $pattern = '/^' . preg_quote($key, '/') . '=.*/m';
    if (preg_match($pattern, $envContent)) {
        $envContent = preg_replace($pattern, "$key=$val", $envContent);
    } else {
        $envContent .= "\n$key=$val";
    }
}

file_put_contents($envFile, $envContent);

// ============================================================
// SUCESSO
// ============================================================

http_response_code(200);
echo json_encode([
    'sucesso' => true,
    'mensagem' => 'Token renovado com sucesso',
    'access_token' => substr($accessToken, 0, 50) . '...',
    'expires_in' => $tokenData['expires_in'] ?? 14400,
    'timestamp' => date('Y-m-d H:i:s'),
]);
?>
