<?php
/**
 * CLI: Gerar Token Diretamente (sem dependência de .htaccess)
 * Executa: php olist/cli-generate-token.php
 */

declare(strict_types=1);

// Detectar se roda via CLI ou Web
$isCli = php_sapi_name() === 'cli';
$isWeb = !$isCli;

if ($isWeb) {
    header('Content-Type: application/json; charset=utf-8');
}

$baseDir = dirname(__DIR__);
$envFile = $baseDir . '/.env';

// ============================================================
// CARREGAR CREDENCIAIS
// ============================================================

$clientId = '';
$clientSecret = '';

if (!is_file($envFile)) {
    $msg = [
        'erro' => 'Arquivo .env não encontrado',
        'caminho' => $envFile,
    ];
    if ($isCli) {
        echo json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    } else {
        echo json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    exit(1);
}

foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with($line, 'OLIST_CLIENT_ID=')) {
        $clientId = trim(explode('=', $line, 2)[1] ?? '');
    } elseif (str_starts_with($line, 'OLIST_CLIENT_SECRET=')) {
        $clientSecret = trim(explode('=', $line, 2)[1] ?? '');
    }
}

if (!$clientId || !$clientSecret) {
    $msg = [
        'erro' => 'Credenciais não configuradas em .env',
        'clientId_ok' => !empty($clientId),
        'clientSecret_ok' => !empty($clientSecret),
    ];
    if ($isCli) {
        echo json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    } else {
        echo json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    exit(1);
}

if ($isCli) {
    echo "═══════════════════════════════════════════════════════════\n";
    echo "GERANDO TOKEN VIA CLIENT CREDENTIALS (CLI)\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    echo "✓ Client ID: " . substr($clientId, 0, 40) . "...\n";
    echo "✓ Client Secret: " . substr($clientSecret, 0, 40) . "...\n\n";
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

if ($isCli) echo "Enviando requisição para $tokenUrl...\n";

$response = @file_get_contents($tokenUrl, false, $context);

if (!$response) {
    $msg = [
        'erro' => 'Falha ao conectar com servidor OAuth Tiny',
        'endpoint' => $tokenUrl,
    ];
    if ($isCli) {
        echo json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    } else {
        echo json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    exit(1);
}

$tokenData = json_decode($response, true);

if (!isset($tokenData['access_token'])) {
    $msg = [
        'erro' => 'Token não obtido',
        'resposta_oauth' => $tokenData,
    ];
    if ($isCli) {
        echo "❌ ERRO:\n";
        echo json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    } else {
        echo json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    exit(1);
}

// ============================================================
// SALVAR TOKEN EM .ENV
// ============================================================

$accessToken = $tokenData['access_token'];
$refreshToken = $tokenData['refresh_token'] ?? '';
$expiresIn = $tokenData['expires_in'] ?? 14400;

if ($isCli) echo "\n✓ Token obtido!\n";
if ($isCli) echo "✓ Salvando em .env...\n";

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

$result = [
    'sucesso' => true,
    'mensagem' => 'Token gerado e salvo com sucesso!',
    'access_token' => substr($accessToken, 0, 50) . '...',
    'expires_in_horas' => round($expiresIn / 3600, 1),
    'arquivo' => '.env',
];

if ($isCli) {
    echo "\n═══════════════════════════════════════════════════════════\n";
    echo "✅ SUCESSO!\n";
    echo "═══════════════════════════════════════════════════════════\n";
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} else {
    http_response_code(200);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>
