<?php
declare(strict_types=1);
header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

/**
 * V16 Commerce Brain — signal tracker
 * POST /api/catalog/signal.php
 * Body: {"event":"view"|"cart_add"|"checkout_start","sku":"X","olist_product_id":"Y"}
 */

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

$event = trim((string)($body['event'] ?? ''));
$sku   = trim((string)($body['sku'] ?? ''));
$pid   = trim((string)($body['olist_product_id'] ?? ''));
$key   = $sku !== '' ? $sku : $pid;

if ($key === '' || !in_array($event, ['view', 'cart_add', 'checkout_start'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_params']);
    exit;
}

$storageDir = dirname(__DIR__, 2) . '/storage';
if (!is_dir($storageDir)) @mkdir($storageDir, 0755, true);
$signalPath = $storageDir . '/commerce_signals.json';

$signals = [];
if (is_file($signalPath)) {
    $signals = json_decode((string)file_get_contents($signalPath), true) ?: [];
}

$map = ['view' => 'views', 'cart_add' => 'cart', 'checkout_start' => 'checkout'];
$bucket = $map[$event];
$signals[$bucket][$key] = (int)($signals[$bucket][$key] ?? 0) + 1;

@file_put_contents($signalPath, json_encode($signals, JSON_UNESCAPED_UNICODE), LOCK_EX);

http_response_code(200);
echo json_encode(['ok' => true, 'event' => $event, 'key' => $key]);
