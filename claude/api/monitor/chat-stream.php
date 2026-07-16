<?php
/**
 * Chat em tempo real - Server-Sent Events (SSE)
 * Permite comunicação bidirecional com agentes
 */

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');

$logFile = __DIR__ . '/../../logs/monitor-chat-stream.log';
@mkdir(dirname($logFile), 0755, true);

$lastEventId = isset($_SERVER['HTTP_LAST_EVENT_ID']) ? (int)$_SERVER['HTTP_LAST_EVENT_ID'] : 0;
$messageFile = __DIR__ . '/../../logs/monitor-messages.jsonl';

// Enviar evento de conexão
send_event('connected', ['timestamp' => date('c'), 'message' => 'Conectado aos agentes']);

// Polling: enviar novas mensagens cada 2 segundos
$iteration = 0;
while ($iteration < 300) { // 10 minutos max
if (file_exists($messageFile)) {
        $lines = @file($messageFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $index => $line) {
            if ($index >= $lastEventId) {
                $message = json_decode(trim($line), true);
                if ($message) {
                    send_event('message', $message);
                    $lastEventId = $index + 1;
                }
            }
        }
    }

    // Check se há resposta dos agentes
    $responseFile = __DIR__ . '/../../logs/monitor-responses.jsonl';
    if (file_exists($responseFile)) {
        $responses = @file($responseFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($responses as $response) {
            $data = json_decode(trim($response), true);
            if ($data && ($data['timestamp'] ?? '') > date('c', time() - 30)) {
                send_event('agent-response', $data);
            }
        }
    }

    flush();
    sleep(2);
    $iteration++;
}

function send_event($type, $data) {
    echo "event: $type\n";
    echo "data: " . json_encode($data) . "\n\n";
    ob_flush();
}
?>
