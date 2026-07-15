<?php
/**
 * Chat Webhook - Receber mensagens do monitor web e registrar
 * Chamado pelo frontend do monitor quando usuario envia mensagem
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Receber JSON do cliente
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || !isset($data['message'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Mensagem nao fornecida']);
        exit;
    }

    $message = trim($data['message']);
    if (empty($message)) {
        http_response_code(400);
        echo json_encode(['error' => 'Mensagem vazia']);
        exit;
    }

    // Criar diretório de logs se não existir
    $logs_dir = __DIR__ . '/../../logs';
    if (!is_dir($logs_dir)) {
        mkdir($logs_dir, 0755, true);
    }

    // Salvar mensagem no log
    $message_log = [
        'timestamp' => date('c'),
        'message' => $message,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
    ];

    $log_file = $logs_dir . '/monitor-messages.log';
    $log_entry = json_encode($message_log, JSON_UNESCAPED_UNICODE) . "\n";

    if (!file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX)) {
        http_response_code(500);
        echo json_encode(['error' => 'Nao foi possivel salvar mensagem']);
        exit;
    }

    // Retornar sucesso
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Mensagem registrada',
        'timestamp' => $message_log['timestamp'],
        'note' => 'Agentes responderao em aproximadamente 2 minutos'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    error_log('Chat webhook error: ' . $e->getMessage());
    echo json_encode(['error' => 'Erro interno']);
}
