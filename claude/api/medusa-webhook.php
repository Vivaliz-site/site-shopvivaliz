<?php
/**
 * Medusa Webhook - Bridge MedusaJS -> EHA
 *
 * Recebe eventos de subscribers do MedusaJS (produto criado, pedido feito,
 * cliente criado, etc), valida a assinatura HMAC e registra a tarefa para
 * o EHA processar (sincronizar marketplaces, notificar, etc).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Medusa-Signature');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$logs_dir = __DIR__ . '/../../storage/logs';
if (!is_dir($logs_dir)) {
    mkdir($logs_dir, 0755, true);
}

function medusa_webhook_log($message, $context = []) {
    global $logs_dir;
    $entry = [
        'timestamp' => date('c'),
        'message' => $message,
        'context' => $context,
    ];
    file_put_contents(
        $logs_dir . '/medusa-webhook.log',
        json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

$raw_body = file_get_contents('php://input');
$webhook_secret = getenv('EHA_WEBHOOK_SECRET') ?: '';

// Valida a assinatura HMAC-SHA256 do payload (header X-Medusa-Signature),
// evitando processar eventos forjados vindos de fora do backend Medusa.
if ($webhook_secret !== '') {
    $received_signature = $_SERVER['HTTP_X_MEDUSA_SIGNATURE'] ?? '';
    $expected_signature = hash_hmac('sha256', $raw_body, $webhook_secret);

    if (!$received_signature || !hash_equals($expected_signature, $received_signature)) {
        medusa_webhook_log('Assinatura invalida ou ausente', [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        ]);
        http_response_code(401);
        echo json_encode(['error' => 'Assinatura invalida']);
        exit;
    }
}

$data = json_decode($raw_body, true);

if (!$data || !isset($data['event'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload invalido, campo "event" obrigatorio']);
    exit;
}

$event = $data['event'];
$payload = $data['data'] ?? [];

medusa_webhook_log('Evento recebido: ' . $event, ['event' => $event, 'data' => $payload]);

// Eventos que geram uma tarefa para o EHA processar (sincronizar
// marketplaces, validar/otimizar produto, notificar seller, etc).
$eha_task_events = [
    'product.created',
    'product.updated',
    'order.placed',
    'customer.created',
];

if (in_array($event, $eha_task_events, true)) {
    $tasks_file = dirname(__DIR__, 2) . '/tasks-queue.json';
    $queue_data = ['queue' => []];

    if (file_exists($tasks_file)) {
        $decoded = json_decode(file_get_contents($tasks_file), true);
        if (is_array($decoded) && isset($decoded['queue']) && is_array($decoded['queue'])) {
            $queue_data = $decoded;
        }
    }

    $queue_data['queue'][] = [
        'id' => 'medusa-' . $event . '-' . bin2hex(random_bytes(6)),
        'title' => 'Evento Medusa: ' . $event,
        'description' => 'Tarefa gerada automaticamente pelo webhook do MedusaJS para o EHA processar.',
        'source' => 'medusa-webhook',
        'event' => $event,
        'payload' => $payload,
        'priority' => 'normal',
        'status' => 'pending',
        'created_at' => date('c'),
    ];

    file_put_contents($tasks_file, json_encode($queue_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

http_response_code(200);
echo json_encode([
    'success' => true,
    'event' => $event,
    'received_at' => date('c'),
]);
