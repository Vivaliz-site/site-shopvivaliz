<?php
/**
 * Chat History - Gerenciar histórico de conversas
 * Endpoints para criar, listar e carregar chats
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Simples arquivo-based storage para histórico
$chat_dir = __DIR__ . '/../../logs/chats';
if (!is_dir($chat_dir)) {
    mkdir($chat_dir, 0755, true);
}

$action = $_GET['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            listChats();
            break;
        case 'create':
            createChat();
            break;
        case 'load':
            loadChat();
            break;
        case 'add-message':
            addMessageToChat();
            break;
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Action not found']);
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log('Chat history error: ' . $e->getMessage());
    echo json_encode(['error' => 'Erro interno']);
}

// Listar todas as conversas
function listChats() {
    global $chat_dir;

    $chats = [];
    foreach (glob($chat_dir . '/*.json') as $file) {
        $data = json_decode((string)file_get_contents($file), true);
        if ($data) {
            $chats[] = [
                'id' => basename($file, '.json'),
                'title' => $data['title'] ?? 'Conversa',
                'messages_count' => count($data['messages'] ?? []),
                'created_at' => $data['created_at'] ?? '',
                'updated_at' => $data['updated_at'] ?? '',
                'first_message' => $data['messages'][0]['content'] ?? ''
            ];
        }
    }

    // Ordenar por updated_at decrescente
    usort($chats, function($a, $b) {
        return strtotime($b['updated_at']) - strtotime($a['updated_at']);
    });

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'chats' => $chats,
        'total' => count($chats)
    ]);
}

// Criar novo chat
function createChat() {
    global $chat_dir;

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || !isset($data['title'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Título não fornecido']);
        exit;
    }

    $chat_id = 'chat_' . time() . '_' . bin2hex(random_bytes(4));
    $timestamp = date('c');

    $chat_data = [
        'id' => $chat_id,
        'title' => $data['title'],
        'messages' => [],
        'created_at' => $timestamp,
        'updated_at' => $timestamp
    ];

    $file = $chat_dir . '/' . $chat_id . '.json';
    if (!file_put_contents($file, json_encode($chat_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX)) {
        http_response_code(500);
        echo json_encode(['error' => 'Não foi possível criar chat']);
        exit;
    }

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'chat_id' => $chat_id,
        'message' => 'Chat criado'
    ]);
}

// Carregar histórico de um chat
function loadChat() {
    global $chat_dir;

    $chat_id = $_GET['chat_id'] ?? null;
    if (!$chat_id) {
        http_response_code(400);
        echo json_encode(['error' => 'chat_id não fornecido']);
        exit;
    }

    $file = $chat_dir . '/' . $chat_id . '.json';
    if (!file_exists($file)) {
        http_response_code(404);
        echo json_encode(['error' => 'Chat não encontrado']);
        exit;
    }

    $chat_data = json_decode((string)file_get_contents($file), true);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'chat' => $chat_data
    ]);
}

// Adicionar mensagem a um chat
function addMessageToChat() {
    global $chat_dir;

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || !isset($data['chat_id']) || !isset($data['message'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Dados incompletos']);
        exit;
    }

    $chat_id = $data['chat_id'];
    $file = $chat_dir . '/' . $chat_id . '.json';

    if (!file_exists($file)) {
        http_response_code(404);
        echo json_encode(['error' => 'Chat não encontrado']);
        exit;
    }

    $chat_data = json_decode((string)file_get_contents($file), true);

    $message = [
        'id' => 'msg_' . time() . '_' . bin2hex(random_bytes(4)),
        'role' => $data['role'] ?? 'user',
        'content' => $data['message'],
        'timestamp' => date('c')
    ];

    if (!isset($chat_data['messages'])) {
        $chat_data['messages'] = [];
    }

    $chat_data['messages'][] = $message;
    $chat_data['updated_at'] = date('c');

    if (!file_put_contents($file, json_encode($chat_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX)) {
        http_response_code(500);
        echo json_encode(['error' => 'Não foi possível salvar mensagem']);
        exit;
    }

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => $message
    ]);
}
