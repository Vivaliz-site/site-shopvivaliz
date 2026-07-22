<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$raw = file_get_contents('php://input') ?: '';
$eventId = 'olist:' . md5($raw !== '' ? $raw : json_encode($_REQUEST));

// Processar webhook antes de responder
require_once __DIR__ . '/webhook-processor.php';

// Responder após processamento
http_response_code(200);
echo json_encode([
    'ok' => true,
    'provider' => 'olist',
    'event_type' => 'erp_event',
    'event_id' => $eventId,
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'received_at' => date('c'),
    'message' => 'Webhook recebido pelo ShopVivaliz.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;

