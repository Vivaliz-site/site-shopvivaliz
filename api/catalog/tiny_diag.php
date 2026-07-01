<?php
// Diagnostic for Tiny price fetching
header('Content-Type: application/json');
$root = dirname(__DIR__, 2);
$token = '';
$envFile = $root . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        if (trim($k) === 'TOKEN_API_OLIST') { $token = trim(trim($v), "\"'"); break; }
    }
}
$hasToken = $token !== '';
$tokenHint = $hasToken ? substr($token, 0, 8) . '...' : 'MISSING';
$url = "https://api.tiny.com.br/api/v2/produtos.json?token={$token}&formato=json&pagina=1&limite=3";
$ctx = stream_context_create(['http' => ['timeout' => 15, 'header' => "User-Agent: ShopVivaliz/1.0\r\n"]]);
$raw = @file_get_contents($url, false, $ctx);
$httpLine = isset($http_response_header) ? $http_response_header[0] : 'no_response';
echo json_encode([
    'has_token' => $hasToken,
    'token_hint' => $tokenHint,
    'http_line' => $httpLine,
    'response_preview' => $raw ? substr($raw, 0, 300) : null,
    'env_exists' => is_file($envFile),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
