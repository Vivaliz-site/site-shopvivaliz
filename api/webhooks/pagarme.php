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
$eventId = 'pagarme:' . md5($raw !== '' ? $raw : json_encode($_REQUEST));

echo json_encode([
    'ok' => true,
    'provider' => 'pagarme',
    'event_type' => 'payment_event',
    'event_id' => $eventId,
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'received_at' => date('c'),
    'message' => 'Webhook recebido pelo ShopVivaliz.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

