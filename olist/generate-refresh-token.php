<?php
/**
 * Gerar TINY_REFRESH_TOKEN usando OAuth flow
 *
 * Execução:
 * php olist/generate-refresh-token.php
 *
 * Segue o fluxo:
 * 1. Gera URL de autenticação (abra no navegador)
 * 2. Usuário clica em permitir
 * 3. Redireciona para callback com código
 * 4. Troca código por refresh_token
 * 5. Salva em .env e storage/private/tokens.json
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
$env_file = "$root/.env";

// Carregar credenciais existentes
$client_id = '';
$client_secret = '';
foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (preg_match('/^TINY_CLIENT_ID=(.+)$/', trim($line), $m)) {
        $client_id = trim($m[1], '\'"');
    }
    if (preg_match('/^TINY_CLIENT_SECRET=(.+)$/', trim($line), $m)) {
        $client_secret = trim($m[1], '\'"');
    }
}

if (!$client_id || !$client_secret) {
    die("❌ TINY_CLIENT_ID ou TINY_CLIENT_SECRET faltando no .env\n");
}

echo "=== Gerador de TINY_REFRESH_TOKEN ===\n\n";
echo "1. Abra este link no navegador:\n\n";

$auth_url = 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth?' . http_build_query([
    'client_id'     => $client_id,
    'redirect_uri'  => 'http://localhost:8888/olist/callback.php',
    'response_type' => 'code',
    'scope'         => 'openid profile email offline_access',
    'state'         => bin2hex(random_bytes(16)),
]);

echo "$auth_url\n\n";
echo "2. Após permitir, você será redirecionado. Cole aqui o CÓDIGO (code=...) da URL:\n";
echo "> ";
$code = trim(fgets(STDIN));

if (empty($code)) {
    die("❌ Código vazio\n");
}

echo "\n⏳ Trocando código por tokens...\n";

$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => implode("\r\n", [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]) . "\r\n",
        'content' => http_build_query([
            'grant_type'    => 'authorization_code',
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'code'          => $code,
            'redirect_uri'  => 'http://localhost:8888/olist/callback.php',
        ]),
        'timeout' => 30,
        'ignore_errors' => true,
    ],
    'ssl' => ['verify_peer' => true],
]);

$response = @file_get_contents(
    'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token',
    false,
    $ctx
);

if (!$response) {
    die("❌ Falha ao conectar com servidor Tiny/Olist\n");
}

$json = json_decode($response, true);
if (!is_array($json)) {
    die("❌ Resposta inválida: " . substr($response, 0, 200) . "\n");
}

if (!empty($json['error'])) {
    die("❌ Erro OAuth: {$json['error']} - {$json['error_description']}\n");
}

if (empty($json['refresh_token'])) {
    die("❌ Nenhum refresh_token na resposta\n");
}

$refresh_token = $json['refresh_token'];
$access_token = $json['access_token'] ?? '';

echo "✅ Tokens obtidos!\n\n";

// Salvar em .env
echo "📝 Atualizando .env...\n";
$env_content = file_get_contents($env_file);
if (strpos($env_content, 'TINY_REFRESH_TOKEN=') === false) {
    $env_content .= "\nTINY_REFRESH_TOKEN=$refresh_token\n";
} else {
    $env_content = preg_replace(
        '/^TINY_REFRESH_TOKEN=.*$/m',
        "TINY_REFRESH_TOKEN=$refresh_token",
        $env_content
    );
}
file_put_contents($env_file, $env_content);

// Salvar em storage/private/tokens.json
echo "💾 Salvando tokens em storage/private/tokens.json...\n";
@mkdir("$root/storage/private", 0750, true);
file_put_contents(
    "$root/storage/private/tokens.json",
    json_encode([
        'TINY_ACCESS_TOKEN' => $access_token,
        'TINY_REFRESH_TOKEN' => $refresh_token,
        'updated_at' => date('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    LOCK_EX
);

echo "\n✅ SUCESSO!\n";
echo "• .env atualizado com TINY_REFRESH_TOKEN\n";
echo "• Tokens salvos em storage/private/tokens.json\n\n";
echo "⚠️  PRÓXIMO PASSO:\n";
echo "1. Adicione TINY_REFRESH_TOKEN=$refresh_token aos GitHub Secrets\n";
echo "2. Acesse: https://github.com/Vivaliz-site/site-shopvivaliz/settings/secrets/actions\n";
echo "3. New repository secret > Nome: TINY_REFRESH_TOKEN > Valor: $refresh_token\n";
echo "4. Salve\n\n";
echo "✅ Sincronização Olist reativada!\n";
