<?php
require __DIR__ . '/config/bootstrap-env.php';
sv_bootstrap_env();
function envv(string $k): string { return (string)getenv($k); }
$clientId = envv('OLIST_CLIENT_ID') ?: envv('TINY_CLIENT_ID');
$clientSecret = envv('OLIST_CLIENT_SECRET') ?: envv('TINY_CLIENT_SECRET');
$refreshToken = envv('OLIST_REFRESH_TOKEN') ?: envv('TINY_REFRESH_TOKEN');
$token = '';
if ($clientId && $clientSecret && $refreshToken) {
    $payload = http_build_query(['grant_type'=>'refresh_token','client_id'=>$clientId,'client_secret'=>$clientSecret,'refresh_token'=>$refreshToken]);
    $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: ShopVivaliz/1.0\r\n",'content'=>$payload,'timeout'=>15]]);
    $raw = @file_get_contents('https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token', false, $ctx);
    if ($raw) { $token = (string)(json_decode($raw, true)['access_token'] ?? ''); }
}
if ($token === '') { $token = envv('OLIST_ACCESS_TOKEN') ?: envv('TINY_ACCESS_TOKEN'); }

function apiGet(string $path, string $token): array {
    $ctx = stream_context_create(['http'=>['header'=>"Authorization: Bearer $token\r\nAccept: application/json\r\n",'timeout'=>20,'ignore_errors'=>true]]);
    $raw = @file_get_contents('https://api.tiny.com.br/public-api/v3' . $path, false, $ctx);
    $status = $http_response_header[0] ?? '';
    return ['status'=>$status, 'data'=>json_decode((string)$raw,true) ?: []];
}

// Tentar filtro dedicado de pedidos com dados incompletos, se existir
$r = apiGet('/pedidos?dataInicial=2026-07-17&dataFinal=2026-07-17&numeroPedido=2998', $token);
echo "Busca por numeroPedido=2998:\n";
echo json_encode($r['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
