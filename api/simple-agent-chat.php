<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$message = $input['message'] ?? '';

if (!$message) {
    http_response_code(400);
    echo json_encode(['error' => 'Mensagem vazia']);
    exit;
}

// Respostas simuladas por palavra-chave
$responses = [
    'qual' => 'A primeira tarefa é sincronizar 198 produtos do Olist. Agentes vão processar automaticamente a cada 5 minutos.',
    'tarefa' => 'Você tem 5 tarefas na fila: Olist, PIX, Imagens, /sobre/, Segurança. Verifique no Dashboard.',
    'agentes' => 'Temos 3 agentes ativos: Claude (desenvolvedor), Gemini (arquiteto), GPT (integrador). Todos trabalhando 24/7.',
    'chat' => 'Este é o chat com agentes! Você pode fazer perguntas e agentes vão responder.',
    'monitor' => 'Você está no Monitor Completo. Abas: Dashboard (números), Tarefas (criar), Chat (conversar).',
    'oi' => 'Oi! Bem-vindo ao ShopVivaliz. Como posso ajudar?',
    'ola' => 'Olá! Como posso ajudar com o ShopVivaliz?',
    'status' => 'Status do sistema: 100% operacional. E-commerce ativo, agentes trabalhando, 5 tarefas em fila.',
    'default' => 'Entendi: "' . substr($message, 0, 50) . '". Agentes estão processando sua mensagem. Pergunte sobre tarefas, status ou agentes!'
];

$response_text = 'Entendi sua pergunta. Como posso ajudar?';
$lower_msg = strtolower($message);

foreach ($responses as $keyword => $response) {
    if ($keyword !== 'default' && strpos($lower_msg, $keyword) !== false) {
        $response_text = $response;
        break;
    }
}

// Se nenhuma correspondência, usar default
if ($response_text === 'Entendi sua pergunta. Como posso ajudar?' && isset($responses['default'])) {
    $response_text = $responses['default'];
}

http_response_code(200);
echo json_encode([
    'success' => true,
    'response' => $response_text,
    'agent' => 'Sistema',
    'timestamp' => date('c')
]);
