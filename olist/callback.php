<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function svo_load_env(string $path): array
{
    $env = [];
    foreach (is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim(trim($value), "\"'");
    }
    return $env;
}

function svo_json_exit(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
    exit;
}

function svo_redirect_uri(array $env): string
{
    $candidate = trim((string)($env['OLIST_REDIRECT_URI'] ?? $env['URL_REDIRCT_OLIST'] ?? $env['TINY_REDIRECT_URI'] ?? ''));
    $host = strtolower((string)(parse_url($candidate, PHP_URL_HOST) ?: ''));
    $path = (string)(parse_url($candidate, PHP_URL_PATH) ?: '');
    return $candidate !== '' && $host === 'shopvivaliz.com.br' && $path === '/olist/callback.php'
        ? $candidate
        : 'https://shopvivaliz.com.br/olist/callback.php';
}

$error = trim((string)($_GET['error'] ?? ''));
$code = trim((string)($_GET['code'] ?? ''));
if ($error !== '') {
    svo_json_exit(400, ['erro' => 'Autorizacao negada', 'oauth_error' => preg_replace('/[^a-z0-9_\-]/i', '', $error)]);
}
if ($code === '') {
    svo_json_exit(400, ['erro' => 'Codigo nao recebido', 'acao' => 'Inicie a autorizacao novamente em /olist/connect.php.']);
}

$envFile = dirname(__DIR__) . '/.env';
$env = svo_load_env($envFile);
$clientId = trim((string)($env['OLIST_CLIENT_ID'] ?? $env['TINY_CLIENT_ID'] ?? $env['CLIENT_ID_API_OLIST'] ?? ''));
$clientSecret = trim((string)($env['OLIST_CLIENT_SECRET'] ?? $env['TINY_CLIENT_SECRET'] ?? $env['CLIENT_SECRET_OLIST'] ?? ''));
if (strlen($clientId) < 16 || strlen($clientSecret) < 16) {
    svo_json_exit(503, ['erro' => 'Credenciais do aplicativo Olist nao configuradas no runtime privado.']);
}

$tokenStorePath = getenv('SHOPVIVALIZ_OLIST_TOKEN_FILE');
if (!is_string($tokenStorePath) || trim($tokenStorePath) === '') {
    $tokenStorePath = PHP_OS_FAMILY === 'Windows'
        ? dirname(__DIR__) . '/storage/private/olist-tokens.json'
        : '/home/ubuntu/shopvivaliz-deploy/shared/private/olist-tokens.json';
}
$tokenStorePath = trim($tokenStorePath);
$tokenStoreDir = dirname($tokenStorePath);
if (!is_dir($tokenStoreDir) || !is_writable($tokenStoreDir)) {
    svo_json_exit(503, ['erro' => 'Armazenamento privado OAuth indisponivel.']);
}

$rotationLock = @fopen($tokenStoreDir . '/olist-token-rotation.lock', 'c+');
if ($rotationLock === false || !flock($rotationLock, LOCK_EX)) {
    svo_json_exit(503, ['erro' => 'Rotacao OAuth ocupada; tente novamente.']);
}
register_shutdown_function(static function () use ($rotationLock): void {
    if (is_resource($rotationLock)) {
        @flock($rotationLock, LOCK_UN);
        @fclose($rotationLock);
    }
});

$postData = http_build_query([
    'grant_type' => 'authorization_code',
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'code' => $code,
    'redirect_uri' => svo_redirect_uri($env),
], '', '&', PHP_QUERY_RFC3986);
$context = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
    'content' => $postData,
    'timeout' => 30,
    'ignore_errors' => true,
]]);
$response = @file_get_contents('https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token', false, $context);
$tokenData = is_string($response) ? json_decode($response, true) : null;
if (!is_array($tokenData) || !is_string($tokenData['access_token'] ?? null) || !is_string($tokenData['refresh_token'] ?? null)) {
    $oauthError = is_array($tokenData) ? (string)($tokenData['error'] ?? '') : '';
    svo_json_exit(401, ['erro' => 'Falha ao trocar codigo por token', 'oauth_error' => preg_replace('/[^a-z0-9_\-]/i', '', $oauthError)]);
}
$accessToken = trim((string)$tokenData['access_token']);
$refreshToken = trim((string)$tokenData['refresh_token']);
if ($accessToken === '' || $refreshToken === '') {
    svo_json_exit(502, ['erro' => 'Resposta OAuth incompleta.']);
}

$store = [];
if (is_file($tokenStorePath) && is_readable($tokenStorePath)) {
    $decoded = json_decode((string)file_get_contents($tokenStorePath), true);
    if (is_array($decoded)) $store = $decoded;
}
$expiresIn = max(0, (int)($tokenData['expires_in'] ?? 0));
$store['OLIST_ACCESS_TOKEN'] = $accessToken;
$store['TINY_ACCESS_TOKEN'] = $accessToken;
$store['OLIST_REFRESH_TOKEN'] = $refreshToken;
$store['TINY_REFRESH_TOKEN'] = $refreshToken;
$store['updated_at'] = gmdate('c');
if ($expiresIn > 0) {
    $expiresAt = time() + $expiresIn;
    $store['expires_in'] = $expiresIn;
    $store['expires_at_epoch'] = $expiresAt;
    $store['expires_at'] = gmdate('c', $expiresAt);
}
$payload = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$tmp = tempnam($tokenStoreDir, '.olist-token-');
if (!is_string($payload) || $tmp === false || file_put_contents($tmp, $payload . PHP_EOL, LOCK_EX) === false) {
    if (is_string($tmp) && is_file($tmp)) @unlink($tmp);
    svo_json_exit(500, ['erro' => 'Falhou a persistencia no armazenamento privado.']);
}
@chmod($tmp, 0660);
if (!@rename($tmp, $tokenStorePath)) {
    @unlink($tmp);
    svo_json_exit(500, ['erro' => 'Falhou a publicacao atomica do token.']);
}
@chmod($tokenStorePath, 0660);

// Compatibility sync is best-effort; the rotating store above is authoritative.
$envSynced = false;
$envTarget = realpath($envFile);
if (is_string($envTarget) && $envTarget !== '' && is_file($envTarget) && is_readable($envTarget) && is_writable($envTarget)) {
    $content = (string)file_get_contents($envTarget);
    foreach ([
        'OLIST_ACCESS_TOKEN' => $accessToken,
        'OLIST_REFRESH_TOKEN' => $refreshToken,
        'TINY_ACCESS_TOKEN' => $accessToken,
        'TINY_REFRESH_TOKEN' => $refreshToken,
        'TOKEN_API_OLIST' => $accessToken,
    ] as $key => $value) {
        $pattern = '/^' . preg_quote($key, '/') . '=.*/m';
        $content = preg_match($pattern, $content)
            ? (string)preg_replace($pattern, $key . '=' . $value, $content, 1)
            : rtrim($content) . PHP_EOL . $key . '=' . $value . PHP_EOL;
    }
    $envTmp = tempnam(dirname($envTarget), '.olist-env-');
    if ($envTmp !== false && file_put_contents($envTmp, rtrim($content) . PHP_EOL, LOCK_EX) !== false) {
        $mode = fileperms($envTarget) & 0777;
        @chmod($envTmp, $mode ?: 0640);
        $envSynced = @rename($envTmp, $envTarget);
    }
    if (is_string($envTmp) && is_file($envTmp)) @unlink($envTmp);
}

svo_json_exit(200, [
    'sucesso' => true,
    'mensagem' => 'OAuth Olist ativado e salvo com sucesso.',
    'token_salvo' => true,
    'token_store_sincronizado' => true,
    'env_sincronizado' => $envSynced,
    'refresh_preventivo' => true,
    'refresh_margin_seconds' => 1800,
]);
