<?php
/**
 * Squad Chat API - ShopVivaliz
 * Endpoint para comunicação entre agentes autônomos
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap-env.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$payload = [];

if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode([
        'status'    => 'error',
        'endpoint'  => 'squad-chat',
        'timestamp' => date('c'),
        'method'    => $method,
        'error'     => 'Method Not Allowed',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    $body    = file_get_contents('php://input');
    $payload = json_decode($body, true) ?? [];
}

$response = [
    'status'    => 'ok',
    'endpoint'  => 'squad-chat',
    'timestamp' => date('c'),
    'method'    => $method,
    'health'    => 'ok',
    'providers' => [
        'openai' => (getenv('OPENAI_API_KEY') ?: '') !== '',
        'gemini' => (getenv('GEMINI_API_KEY') ?: '') !== '',
        'anthropic' => (getenv('ANTHROPIC_API_KEY') ?: '') !== '',
    ],
];

if (!empty($payload)) {
    $response['received'] = $payload;
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
