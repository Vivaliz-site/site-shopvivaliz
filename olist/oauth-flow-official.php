<?php
/**
 * OAuth 2.0 Flow Completo - Seguindo EXATAMENTE documentação oficial Olist ERP API V3
 * Referência: https://api-docs.erp.olist.com/documentacao/comecando/autenticacao
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: application/json; charset=utf-8');

// ============================================================
// PASSO 1: SOLICITAÇÃO DE AUTORIZAÇÃO (Redireciona usuário)
// ============================================================

// Carregar .env
if (is_file(dirname(__DIR__) . '/.env')) {
    foreach (file(dirname(__DIR__) . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, 'OLIST_CLIENT_ID=')) {
            putenv($line);
        } elseif (str_starts_with($line, 'OLIST_CLIENT_SECRET=')) {
            putenv($line);
        }
    }
}

if (!isset($_GET['code']) && !isset($_GET['error'])) {
    // Usuário ainda não clicou no link
    $clientId = getenv('OLIST_CLIENT_ID') ?: 'CONFIGURE OLIST_CLIENT_ID NO .env';
    $redirectUri = getenv('OLIST_OFFICIAL_REDIRECT_URI') ?: "https://shopvivaliz.com.br/olist/oauth-flow-official.php";

    $authUrl = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth?" . http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'scope' => 'openid',
        'response_type' => 'code',
    ]);

    echo json_encode([
        'passo' => 1,
        'descricao' => 'Solicitação de Autorização',
        'acao' => 'Clique no link abaixo para fazer login e autorizar',
        'link' => $authUrl,
        'instruções' => [
            '1. Copie o link acima',
            '2. Cole no navegador',
            '3. Faça login com sua conta Olist ERP',
            '4. Clique "Autorizar"',
            '5. Será redirecionado de volta AUTOMATICAMENTE',
            '6. Os tokens serão salvos em .env',
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ============================================================
// ERRO: Usuário negou autorização
// ============================================================

if (isset($_GET['error'])) {
    http_response_code(400);
    echo json_encode([
        'passo' => 'ERRO',
        'erro' => $_GET['error'],
        'descricao' => $_GET['error_description'] ?? 'Sem descrição',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ============================================================
// PASSO 2: OBTENÇÃO DO CÓDIGO DE AUTORIZAÇÃO
// ============================================================

$code = $_GET['code'] ?? null;

if (!$code) {
    http_response_code(400);
    echo json_encode([
        'passo' => 2,
        'erro' => 'Código de autorização não recebido',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

echo json_encode([
    'passo' => 2,
    'descricao' => 'Código de Autorização Recebido',
    'code' => substr($code, 0, 40) . '...',
    'proxima_acao' => 'Trocando código por token...',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// ============================================================
// PASSO 3: SOLICITAÇÃO DE TOKEN DE ACESSO (POST)
// ============================================================

$clientId = getenv('OLIST_CLIENT_ID') ?: die('ERRO: OLIST_CLIENT_ID não configurado em .env');
$clientSecret = getenv('OLIST_CLIENT_SECRET') ?: die('ERRO: OLIST_CLIENT_SECRET não configurado em .env');
$redirectUri = getenv('OLIST_OFFICIAL_REDIRECT_URI') ?: "https://shopvivaliz.com.br/olist/oauth-flow-official.php";

$tokenEndpoint = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token";

$postData = http_build_query([
    'grant_type' => 'authorization_code',
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri' => $redirectUri,
    'code' => $code,
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

$response = @file_get_contents($tokenEndpoint, false, $context);

if (!$response) {
    http_response_code(500);
    echo json_encode([
        'passo' => 3,
        'erro' => 'Falha ao conectar com servidor OAuth',
        'endpoint' => $tokenEndpoint,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$tokenData = json_decode($response, true);

if (!isset($tokenData['access_token'])) {
    http_response_code(401);
    echo json_encode([
        'passo' => 3,
        'erro' => 'Token de acesso não obtido',
        'resposta_oauth' => $tokenData,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ============================================================
// PASSO 4: SALVAR TOKENS EM .env
// ============================================================

$accessToken = $tokenData['access_token'];
$refreshToken = $tokenData['refresh_token'] ?? '';
$expiresIn = $tokenData['expires_in'] ?? 14400;

$envFile = dirname(__DIR__) . '/.env';
$envContent = is_file($envFile) ? file_get_contents($envFile) : '';

// Atualizar ou adicionar tokens
$replacements = [
    'OLIST_ACCESS_TOKEN' => $accessToken,
    'OLIST_REFRESH_TOKEN' => $refreshToken,
    'TINY_ACCESS_TOKEN' => $accessToken,
    'TINY_REFRESH_TOKEN' => $refreshToken,
];

foreach ($replacements as $key => $value) {
    if (preg_match('/^' . preg_quote($key, '/') . '=.*/m', $envContent)) {
        $envContent = preg_replace(
            '/^' . preg_quote($key, '/') . '=.*/m',
            $key . '=' . $value,
            $envContent
        );
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
    'passo' => '✅ COMPLETO',
    'descricao' => 'OAuth Flow Finalizado com Sucesso!',
    'access_token' => substr($accessToken, 0, 50) . '...',
    'refresh_token' => $refreshToken ? (substr($refreshToken, 0, 50) . '...') : 'não fornecido',
    'expires_in_seconds' => $expiresIn,
    'expires_in_hours' => round($expiresIn / 3600, 1),
    'arquivo_atualizado' => $envFile,
    'proximo_passo' => [
        '1. GitHub Secrets: OLIST_ACCESS_TOKEN (copie o access_token)',
        '2. Workflow sync-olist-6h será disparado automaticamente',
        '3. Produtos serão sincronizados do ERP a cada 5 minutos',
        '4. Depois de validar, alterar para 2 horas',
    ]
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
