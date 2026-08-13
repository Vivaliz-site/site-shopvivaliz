<?php
/**
 * OAuth Callback - troca codigo por token e salva no runtime privado.
 * Documentacao: https://api-docs.erp.olist.com/documentacao/comecando/autenticacao
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$envFile = dirname(__DIR__) . '/.env';
$clientId = '';
$clientSecret = '';

if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, 'OLIST_CLIENT_ID=')) {
            $clientId = trim((string)(explode('=', $line, 2)[1] ?? ''), " \t\n\r\0\x0B\"'");
        } elseif (str_starts_with($line, 'OLIST_CLIENT_SECRET=')) {
            $clientSecret = trim((string)(explode('=', $line, 2)[1] ?? ''), " \t\n\r\0\x0B\"'");
        }
    }
}

if (strlen($clientId) < 16 && is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, 'TINY_CLIENT_ID=')) {
            $clientId = trim((string)(explode('=', $line, 2)[1] ?? ''), " \t\n\r\0\x0B\"'");
        }
    }
}
if (strlen($clientSecret) < 16 && is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, 'TINY_CLIENT_SECRET=')) {
            $clientSecret = trim((string)(explode('=', $line, 2)[1] ?? ''), " \t\n\r\0\x0B\"'");
        }
    }
}

$error = $_GET['error'] ?? null;
$code = $_GET['code'] ?? null;

if ($error) {
    http_response_code(400);
    echo json_encode([
        'erro' => 'Autorizacao negada',
        'oauth_error' => preg_replace('/[^a-z0-9_\-]/i', '', (string)$error),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if (!$code) {
    http_response_code(400);
    echo json_encode([
        'erro' => 'Codigo nao recebido',
        'acao' => 'Inicie a autorizacao novamente.',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if (strlen($clientId) < 16 || strlen($clientSecret) < 16) {
    http_response_code(503);
    echo json_encode([
        'erro' => 'Credenciais do aplicativo Olist nao configuradas no runtime privado.',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$configuredRedirectUri = getenv('OLIST_REDIRECT_URI') ?: getenv('URL_REDIRCT_OLIST') ?: getenv('TINY_REDIRECT_URI') ?: '';
$redirectHost = strtolower((string)(parse_url($configuredRedirectUri, PHP_URL_HOST) ?: ''));
$redirectUri = ($redirectHost === 'shopvivaliz.com.br' && (string)(parse_url($configuredRedirectUri, PHP_URL_PATH) ?: '') === '/olist/callback.php')
    ? $configuredRedirectUri
    : 'https://shopvivaliz.com.br/olist/callback.php';
$tokenUrl = 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token';

$postData = http_build_query([
    'grant_type' => 'authorization_code',
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'code' => (string)$code,
    'redirect_uri' => $redirectUri,
], '', '&', PHP_QUERY_RFC3986);

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
        'content' => $postData,
        'timeout' => 30,
        'ignore_errors' => true,
    ],
]);

$response = @file_get_contents($tokenUrl, false, $context);
$tokenData = is_string($response) ? json_decode($response, true) : null;

if (!is_array($tokenData) || !isset($tokenData['access_token'])) {
    $oauthError = is_array($tokenData) ? (string)($tokenData['error'] ?? '') : '';
    http_response_code(401);
    echo json_encode([
        'erro' => 'Falha ao trocar codigo por token',
        'oauth_error' => preg_replace('/[^a-z0-9_\-]/i', '', $oauthError),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$accessToken = trim((string)$tokenData['access_token']);
$refreshToken = trim((string)($tokenData['refresh_token'] ?? ''));
$expiresIn = max(0, (int)($tokenData['expires_in'] ?? 0));

if ($accessToken === '' || $refreshToken === '') {
    http_response_code(502);
    echo json_encode([
        'erro' => 'Resposta OAuth incompleta: access/refresh token obrigatorios.',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// O token store compartilhado e a gravacao obrigatoria do callback. Ele e
// provisionado pelo renovador com grupo www-data e modo 0660, enquanto o .env
// compartilhado pode permanecer 0640 e somente leitura para o processo web.
$tokenStorePath = getenv('SHOPVIVALIZ_OLIST_TOKEN_FILE');
if (!is_string($tokenStorePath) || trim($tokenStorePath) === '') {
    $tokenStorePath = PHP_OS_FAMILY === 'Windows'
        ? dirname(__DIR__) . '/storage/private/olist-tokens.json'
        : '/home/ubuntu/shopvivaliz-deploy/shared/private/olist-tokens.json';
}
$tokenStorePath = trim($tokenStorePath);
$tokenStoreDir = dirname($tokenStorePath);

if (!is_dir($tokenStoreDir) || !is_writable($tokenStoreDir)) {
    http_response_code(503);
    echo json_encode([
        'erro' => 'Armazenamento privado OAuth indisponivel. Reinicie o renovador Olist e tente novamente.',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$store = [];
if (is_file($tokenStorePath) && is_readable($tokenStorePath)) {
    $decodedStore = json_decode((string)file_get_contents($tokenStorePath), true);
    if (is_array($decodedStore)) {
        $store = $decodedStore;
    }
}
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

$storePayload = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$tokenStoreSaved = false;
if (is_string($storePayload)) {
    $storeTmp = tempnam($tokenStoreDir, '.olist-token-');
    if ($storeTmp !== false) {
        if (file_put_contents($storeTmp, $storePayload . PHP_EOL, LOCK_EX) !== false) {
            @chmod($storeTmp, 0660);
            $tokenStoreSaved = @rename($storeTmp, $tokenStorePath);
            if ($tokenStoreSaved) {
                @chmod($tokenStorePath, 0660);
            }
        }
        if (is_file($storeTmp)) {
            @unlink($storeTmp);
        }
    }
}

if (!$tokenStoreSaved) {
    http_response_code(500);
    echo json_encode([
        'erro' => 'Token obtido, mas falhou a persistencia no armazenamento privado.',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Sincronizar o .env e best-effort. O daemon roda como ubuntu e reconcilia o
// .env na proxima renovacao mesmo quando o processo web nao possui escrita.
$envSynced = false;
$envTarget = realpath($envFile);
if (is_string($envTarget) && $envTarget !== '' && is_file($envTarget) && is_readable($envTarget) && is_writable($envTarget)) {
    $envContent = (string)file_get_contents($envTarget);
    $replacements = [
        'OLIST_ACCESS_TOKEN' => $accessToken,
        'OLIST_REFRESH_TOKEN' => $refreshToken,
        'TINY_ACCESS_TOKEN' => $accessToken,
        'TINY_REFRESH_TOKEN' => $refreshToken,
        'TOKEN_API_OLIST' => $accessToken,
    ];
    foreach ($replacements as $key => $value) {
        $pattern = '/^' . preg_quote($key, '/') . '=.*/m';
        if (preg_match($pattern, $envContent)) {
            $envContent = (string)preg_replace($pattern, $key . '=' . $value, $envContent);
        } else {
            $envContent .= ($envContent !== '' && !str_ends_with($envContent, "\n") ? "\n" : '') . $key . '=' . $value . "\n";
        }
    }

    $envTmp = tempnam(dirname($envTarget), '.olist-env-');
    if ($envTmp !== false) {
        if (file_put_contents($envTmp, $envContent, LOCK_EX) !== false) {
            $mode = fileperms($envTarget) & 0777;
            @chmod($envTmp, $mode ?: 0640);
            $envSynced = @rename($envTmp, $envTarget);
        }
        if (is_file($envTmp)) {
            @unlink($envTmp);
        }
    }
}

http_response_code(200);
echo json_encode([
    'sucesso' => true,
    'mensagem' => 'OAuth Olist ativado e salvo com sucesso.',
    'token_salvo' => true,
    'token_store_sincronizado' => true,
    'env_sincronizado' => $envSynced,
    'refresh_preventivo' => true,
    'refresh_margin_seconds' => 1800,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
