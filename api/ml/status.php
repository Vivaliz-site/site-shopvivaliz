<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

// Rodada 5 (2026-08-19): parte do lote de api/ml/* sem autenticacao. Ver
// R5-1 no relatorio da Rodada 5.
require_once dirname(__DIR__, 2) . '/config/require-agent-key.php';
sv_require_agent_key();

function ml_status_json(string $url, int $timeout = 3): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if (!is_string($body) || $body === '') {
        return ['ok' => false, 'http_code' => $code, 'error' => $error !== '' ? $error : 'empty_response'];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'http_code' => $code, 'error' => 'invalid_json'];
    }

    return ['ok' => $code >= 200 && $code < 300, 'http_code' => $code, 'data' => $decoded];
}

$health = ml_status_json('http://127.0.0.1:8091/health');
$status = ml_status_json('http://127.0.0.1:8091/status');

$response = [
    'ok' => ($health['ok'] ?? false) || ($status['ok'] ?? false),
    'checked_at' => gmdate('c'),
    'api' => $health,
    'status' => $status,
];

if (!($response['ok'])) {
    http_response_code(503);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
