<?php
declare(strict_types=1);
require_once __DIR__ . '/client.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method !== 'POST') {
    echo json_encode(['ok' => true, 'message' => 'Webhook ML ativo. Use POST para notificações.']);
    exit;
}

$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true) ?? [];

$entry = [
    'received_at'    => gmdate('c'),
    'topic'          => trim($_GET['topic']          ?? ($body['topic'] ?? '')),
    'resource'       => trim($_GET['resource']       ?? ($body['resource'] ?? '')),
    'user_id'        => trim($_GET['user_id']        ?? (string)($body['user_id'] ?? '')),
    'application_id' => trim($_GET['application_id'] ?? (string)($body['application_id'] ?? '')),
    'body'           => $body,
];

$logDir = ml_root() . '/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
file_put_contents($logDir . '/ml-webhooks.log', $line, FILE_APPEND | LOCK_EX);

echo json_encode(['ok' => true]);
