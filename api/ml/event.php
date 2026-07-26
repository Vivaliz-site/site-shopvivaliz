<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
$allowed = ['page_view', 'click', 'add_to_cart', 'purchase'];
$eventType = is_array($input) ? trim((string)($input['event_type'] ?? '')) : '';
$productId = is_array($input) ? substr(trim((string)($input['product_id'] ?? '')), 0, 191) : '';
if (!in_array($eventType, $allowed, true) || $productId === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_event']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$visitorSeed = session_id() . '|' . ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '');
$payload = json_encode([
    'event_type' => $eventType,
    'product_id' => $productId,
    'visitor_id' => hash('sha256', $visitorSeed),
    'session_id' => session_id() !== '' ? hash('sha256', session_id()) : null,
    'metadata' => [
        'path' => substr((string)($input['path'] ?? ''), 0, 500),
        'source' => 'storefront_php',
    ],
], JSON_UNESCAPED_SLASHES);

$ch = curl_init('http://127.0.0.1:8091/event');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 4,
]);
$body = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($body === false || $status !== 202) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'collector_unavailable']);
    exit;
}
http_response_code(202);
echo json_encode(['ok' => true]);
