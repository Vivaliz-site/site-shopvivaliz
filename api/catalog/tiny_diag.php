<?php
// Diagnostic for Tiny API v3 OAuth price fetching
header('Content-Type: application/json');
$root = dirname(__DIR__, 2);

function read_env(string $key, string $root): string {
    $envFile = $root . '/.env';
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            if (trim($k) === $key) return trim(trim($v), "\"'");
        }
    }
    return (string)getenv($key);
}

$clientId     = read_env('OLIST_CLIENT_ID', $root);
$clientSecret = read_env('OLIST_CLIENT_SECRET', $root);
$refreshToken = read_env('OLIST_REFRESH_TOKEN', $root);
$accessToken  = read_env('OLIST_ACCESS_TOKEN', $root);

$result = [
    'has_client_id'     => !empty($clientId),
    'has_client_secret' => !empty($clientSecret),
    'has_refresh_token' => !empty($refreshToken),
    'has_access_token'  => !empty($accessToken),
    'client_id_hint'    => $clientId ? substr($clientId, 0, 10) . '...' : 'MISSING',
    'refresh_token_hint'=> $refreshToken ? substr($refreshToken, 0, 10) . '...' : 'MISSING',
];

// Try refresh flow
if ($clientId && $clientSecret && $refreshToken) {
    $payload = http_build_query([
        'grant_type'    => 'refresh_token',
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
    ]);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: ShopVivaliz/1.0\r\n",
        'content' => $payload,
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents(
        'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token',
        false, $ctx
    );
    $tokenHttpLine = isset($http_response_header) ? $http_response_header[0] : 'no_response';
    $result['token_refresh_http'] = $tokenHttpLine;
    if ($raw) {
        $data = json_decode($raw, true);
        $at = (string)($data['access_token'] ?? '');
        $result['refresh_ok'] = $at !== '';
        $result['new_access_token_hint'] = $at ? substr($at, 0, 12) . '...' : null;
        $result['refresh_error'] = isset($data['error']) ? $data['error'] : null;
        $result['refresh_error_desc'] = $data['error_description'] ?? null;
        $result['refresh_raw'] = $raw ? substr($raw, 0, 300) : null;
        if ($at) {
            // Test v3 products API
            $ctx3 = stream_context_create(['http' => [
                'timeout' => 15,
                'header'  => "Authorization: Bearer {$at}\r\nUser-Agent: ShopVivaliz/1.0\r\nAccept: application/json\r\n",
            ]]);
            $raw3 = @file_get_contents('https://api.tiny.com.br/public-api/v3/produtos?pagina=1&limite=3', false, $ctx3);
            $v3HttpLine = isset($http_response_header) ? $http_response_header[0] : 'no_response';
            $result['v3_http'] = $v3HttpLine;
            if ($raw3) {
                $d3 = json_decode($raw3, true);
                $result['v3_product_count'] = count($d3['data']['itens'] ?? []);
                $result['v3_first_product'] = isset($d3['data']['itens'][0]) ? [
                    'id' => $d3['data']['itens'][0]['id'] ?? null,
                    'codigo' => $d3['data']['itens'][0]['codigo'] ?? null,
                    'preco' => $d3['data']['itens'][0]['preco'] ?? null,
                ] : null;
            }
        }
    } else {
        $result['refresh_ok'] = false;
        $result['refresh_error'] = 'no_response';
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
