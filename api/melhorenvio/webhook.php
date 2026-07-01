<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$raw = file_get_contents('php://input') ?: '';
$eventId = 'melhorenvio:' . md5($raw !== '' ? $raw : json_encode($_REQUEST));

echo json_encode([
    'ok' => true,
    'provider' => 'melhorenvio',
    'event_type' => 'shipping_event',
    'event_id' => $eventId,
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'received_at' => date('c'),
    'message' => 'Webhook recebido pelo ShopVivaliz.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

