<?php
/**
 * Teste do Token V3 - Mostra resposta COMPLETA da API Olist
 * Não apenas "Status 403", mas a RAZÃO real do erro
 */

// Carregar .env
if (is_file(dirname(__DIR__) . '/.env')) {
    foreach (file(dirname(__DIR__) . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, 'OLIST_ACCESS_TOKEN=')) {
            putenv($line);
        }
    }
}

$accessToken = getenv('OLIST_ACCESS_TOKEN') ?: null;

header('Content-Type: application/json; charset=utf-8');

if (!$accessToken) {
    http_response_code(400);
    echo json_encode([
        'erro' => 'Token não configurado',
        'acao' => 'Execute o OAuth flow primeiro: https://shopvivaliz.com.br/olist/callback.php',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

echo "═══════════════════════════════════════════════════════════\n";
echo "TESTANDO ACCESS TOKEN V3\n";
echo "═══════════════════════════════════════════════════════════\n\n";

// Testar com file_get_contents (sem curl)
$url = 'https://api.tiny.com.br/public-api/v3/produtos?limit=10&offset=0';

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer $accessToken\r\nAccept: application/json\r\n",
        'timeout' => 30,
    ],
    'https' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer $accessToken\r\nAccept: application/json\r\n",
        'timeout' => 30,
    ]
]);

$response = @file_get_contents($url, false, $context);
$statusLine = isset($http_response_header) ? $http_response_header[0] : 'Unknown';
$status = intval(explode(' ', $statusLine)[1] ?? 0);

echo json_encode([
    'teste' => 'GET /public-api/v3/produtos',
    'token' => substr($accessToken, 0, 40) . '...',
    'status_http' => $status,
    'status_linha' => $statusLine,
    'resposta_completa' => json_decode($response, true) ?? $response,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
