<?php
require __DIR__ . '/config/bootstrap-env.php';
sv_bootstrap_env();

function envv(string $k): string { return (string)getenv($k); }
$token = envv('OLIST_ACCESS_TOKEN') ?: envv('TINY_ACCESS_TOKEN');

$ctx = stream_context_create(['http' => [
    'header' => "Authorization: Bearer $token\r\nAccept: application/json\r\n",
    'timeout' => 15,
    'ignore_errors' => true,
]]);
$raw = @file_get_contents('https://api.tiny.com.br/public-api/v3/estoque/342902474', false, $ctx);
$status = $http_response_header[0] ?? 'no response';
echo "$status\n" . (string)$raw . "\n";
