<?php
/**
 * Simple Chat API - Agentes sem autenticação
 * POST /api/monitor/simple-chat.php
 * Sem necessidade de SQUAD_TOKEN
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Receber mensagem
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$message = $input['message'] ?? '';

if (!$message) {
    http_response_code(400);
    echo json_encode(['error' => 'Mensagem vazia']);
    exit;
}

// Salvar mensagem
@mkdir('../../logs', 0755, true);
$log_file = '../../logs/monitor-messages.log';
$msg_data = [
    'timestamp' => date('c'),
    'message' => $message,
    'source' => 'monitor-v2'
];
file_put_contents($log_file, json_encode($msg_data) . "\n", FILE_APPEND);

// Responder com mensagem de agentes
$responses_file = '../../logs/monitor-responses.jsonl';
$latest_response = null;

if (file_exists($responses_file)) {
    $lines = file($responses_file);
    if (!empty($lines)) {
        $latest_response = json_decode(end($lines), true);
    }
}

// Retornar resposta simulada
http_response_code(200);
echo json_encode([
    'success' => true,
    'message_saved' => true,
    'timestamp' => date('c'),
    'response' => $latest_response ? $latest_response['agent_response'] : 'Agentes processando sua mensagem. Aguarde alguns segundos.',
    'agent' => $latest_response ? $latest_response['agent'] : 'System',
    'status' => 'processing'
]);
