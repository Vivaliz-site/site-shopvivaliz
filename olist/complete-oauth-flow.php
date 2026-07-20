<?php
/**
 * Complete OAuth Flow
 * Troca codigo por token e sincroniza produtos.
 *
 * Executa automaticamente apos oauth-callback-simple.php salvar o codigo.
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(900);

function log_msg($msg) {
    $log_file = __DIR__ . '/../logs/olist-complete-oauth-flow.log';
    @mkdir(dirname($log_file), 0755, true);

    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($log_file, "[$timestamp] $msg\n", FILE_APPEND);
    error_log("[Complete OAuth] $msg");
}

function exit_error($msg) {
    log_msg("ERRO: $msg");
    http_response_code(400);
    echo json_encode([
        'erro' => $msg,
        'sucesso' => false,
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function olist_complete_persist_tokens(string $accessToken, string $refreshToken): void {
    $root = dirname(__DIR__);
    $privateDir = $root . '/storage/private';
    @mkdir($privateDir, 0750, true);
    @file_put_contents($privateDir . '/tokens.json', json_encode([
        'OLIST_ACCESS_TOKEN' => $accessToken,
        'OLIST_REFRESH_TOKEN' => $refreshToken,
        'TINY_ACCESS_TOKEN' => $accessToken,
        'TINY_REFRESH_TOKEN' => $refreshToken,
        'updated_at' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

    $envFile = $root . '/.env';
    $envContent = is_file($envFile) ? (string)file_get_contents($envFile) : '';
    foreach ([
        'OLIST_ACCESS_TOKEN' => $accessToken,
        'OLIST_REFRESH_TOKEN' => $refreshToken,
        'TINY_ACCESS_TOKEN' => $accessToken,
        'TINY_REFRESH_TOKEN' => $refreshToken,
    ] as $key => $value) {
        if (preg_match('/^' . preg_quote($key, '/') . '=.*/m', $envContent)) {
            $envContent = (string)preg_replace('/^' . preg_quote($key, '/') . '=.*/m', $key . '=' . $value, $envContent);
        } else {
            $envContent .= rtrim($envContent) === '' ? '' : PHP_EOL;
            $envContent .= $key . '=' . $value;
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
    @file_put_contents($envFile, $envContent);
}

$client_id = getenv('OLIST_CLIENT_ID') ?: die('ERRO: OLIST_CLIENT_ID nao configurado');
$client_secret = getenv('OLIST_CLIENT_SECRET') ?: die('ERRO: OLIST_CLIENT_SECRET nao configurado');
$redirect_uri = getenv('OLIST_REDIRECT_URI') ?: getenv('URL_REDIRCT_OLIST') ?: 'https://shopvivaliz.com.br/olist/oauth-callback-simple.php';

log_msg('=== COMPLETE OAUTH FLOW ===');

try {
    log_msg('PASSO 1: Procurando codigo salvo...');

    $code_file = __DIR__ . '/../.tokens/olist-oauth-code.txt';
    if (!file_exists($code_file)) {
        exit_error('Codigo nao encontrado. Faca login em /olist/oauth-callback-simple.php primeiro');
    }

    $code = trim((string)file_get_contents($code_file));
    if ($code === '' || strlen($code) < 10) {
        exit_error('Codigo invalido');
    }

    log_msg('  Codigo encontrado: ' . substr($code, 0, 30) . '...');
    log_msg('PASSO 2: Trocando codigo por token...');

    $ch = curl_init('https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'code' => $code,
            'redirect_uri' => $redirect_uri
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status != 200) {
        log_msg("  ERRO: Status $status");
        exit_error('Falha ao trocar codigo por token');
    }

    $token_data = json_decode((string)$response, true);
    if (!isset($token_data['access_token']) || !isset($token_data['refresh_token'])) {
        log_msg('  ERRO: Tokens nao recebidos');
        exit_error('Tokens invalidos na resposta');
    }

    log_msg('  Token obtido com sucesso!');

    $config = [
        'access_token' => $token_data['access_token'],
        'refresh_token' => $token_data['refresh_token'],
        'token_type' => $token_data['token_type'] ?? 'Bearer',
        'expires_in' => $token_data['expires_in'] ?? 14400,
        'created_at' => date('c')
    ];

    $token_dir = __DIR__ . '/../.tokens';
    @mkdir($token_dir, 0777, true);
    $config_file = $token_dir . '/olist-config.json';
    file_put_contents($config_file, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    olist_complete_persist_tokens((string)$token_data['access_token'], (string)$token_data['refresh_token']);
    log_msg("  Config salvo em: $config_file");

    @unlink($code_file);

    log_msg('PASSO 3: Sincronizando produtos via olist/sync-products.php (API v3 real)...');

    // A busca antiga (API v2 legada) so gravava em logs/olist-products-cache.json,
    // um arquivo que o site nunca le -- ver docs/MEMORIA-AGENTES.md. O catalogo real
    // e api/catalog/fallback-products.json, mantido por sync-products.php (v3 OAuth).
    $sync_output = shell_exec('php ' . escapeshellarg(__DIR__ . '/sync-products.php') . ' 2>&1');
    log_msg('  Saida do sync v3: ' . trim((string)$sync_output));

    log_msg('=== FLUXO OAUTH COMPLETO COM SUCESSO ===');

    http_response_code(200);
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'OAuth completo! Catalogo sincronizado via API v3.',
        'sync_output' => trim((string)$sync_output),
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Exception $e) {
    log_msg('EXCEPTION: ' . $e->getMessage());
    exit_error('Erro: ' . $e->getMessage());
}
