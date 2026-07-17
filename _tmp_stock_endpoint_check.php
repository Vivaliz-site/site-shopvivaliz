<?php
require __DIR__ . '/config/bootstrap-env.php';
sv_bootstrap_env();

function envv(string $k): string { return (string)getenv($k); }

$clientId = envv('OLIST_CLIENT_ID') ?: envv('TINY_CLIENT_ID');
$clientSecret = envv('OLIST_CLIENT_SECRET') ?: envv('TINY_CLIENT_SECRET');
$refreshToken = envv('OLIST_REFRESH_TOKEN') ?: envv('TINY_REFRESH_TOKEN');

$token = '';
if ($clientId && $clientSecret && $refreshToken) {
    $payload = http_build_query([
        'grant_type' => 'refresh_token',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
    ]);
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: ShopVivaliz/1.0\r\n",
        'content' => $payload,
        'timeout' => 15,
    ]]);
    $raw = @file_get_contents('https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token', false, $ctx);
    if ($raw) {
        $data = json_decode($raw, true);
        $token = (string)($data['access_token'] ?? '');
    }
}
if ($token === '') {
    $token = envv('OLIST_ACCESS_TOKEN') ?: envv('TINY_ACCESS_TOKEN');
}

$candidates = [
    '/estoque/342902474',
    '/estoque/342902474/saldo',
    '/produtos/342902474/estoque',
    '/produtos/estoque/342902474',
    '/estoque?idProduto=342902474',
];

foreach ($candidates as $path) {
    $ctx = stream_context_create(['http' => [
        'header' => "Authorization: Bearer $token\r\nAccept: application/json\r\n",
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents('https://api.tiny.com.br/public-api/v3' . $path, false, $ctx);
    $status = $http_response_header[0] ?? 'no response';
    echo "=== $path ===\n$status\n" . substr((string)$raw, 0, 500) . "\n\n";
}
