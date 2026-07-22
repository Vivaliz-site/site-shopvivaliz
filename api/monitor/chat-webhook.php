<?php
/**
 * Chat Webhook - Recebe mensagens de chat e as persiste
 * POST /api/monitor/chat-webhook.php
 */

declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function chat_webhook_response(int $status, string $ok, string $message = ''): never
{
    http_response_code($status);
    $response = [
        'ok' => $ok === 'true',
        'timestamp' => date('c'),
        'endpoint' => 'chat-webhook',
    ];
    if ($message !== '') {
        $response['message'] = $message;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    chat_webhook_response(405, 'false', 'Method Not Allowed');
}

$raw = (string)file_get_contents('php://input');
if ($raw === '') {
    chat_webhook_response(400, 'false', 'Empty payload');
}

if (strlen($raw) > 100000) {
    chat_webhook_response(413, 'false', 'Payload too large');
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    chat_webhook_response(400, 'false', 'Invalid JSON');
}

$message = trim((string)($payload['message'] ?? $payload['text'] ?? ''));
$senderId = trim((string)($payload['sender_id'] ?? $payload['user_id'] ?? ''));
$chatId = trim((string)($payload['chat_id'] ?? $payload['session_id'] ?? ''));

if ($message === '') {
    chat_webhook_response(400, 'false', 'Message is required');
}

// Log message
$logsDir = dirname(__DIR__, 2) . '/logs/chat-messages';
@mkdir($logsDir, 0755, true);

$timestamp = date('Y-m-d_H-i-s');
$logEntry = [
    'timestamp' => date('c'),
    'message' => $message,
    'sender_id' => $senderId ?: 'anonymous',
    'chat_id' => $chatId ?: 'unknown',
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
];

// Append to JSONL log
$logFile = "$logsDir/chat-messages.jsonl";
file_put_contents(
    $logFile,
    json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

chat_webhook_response(200, 'true', 'Message received and logged');
?>
