<?php
/**
 * Squad Chat API - ShopVivaliz
 * Endpoint para comunicação entre agentes autônomos
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = [];

if ($method === 'POST') {
    $body    = file_get_contents('php://input');
    $payload = json_decode($body, true) ?? [];
}

$response = [
    'status'    => 'ok',
    'endpoint'  => 'squad-chat',
    'timestamp' => date('c'),
    'method'    => $method,
];

if (!empty($payload)) {
    $response['received'] = $payload;
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
