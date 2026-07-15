<?php
/**
 * Endpoint para enviar comandos/tarefas aos agentes via monitor
 * POST api/monitor/tasks-command.php
 *
 * Payload:
 * {
 *   "action": "create_task",
 *   "title": "Criar página X",
 *   "description": "Detalhes da tarefa",
 *   "priority": "high|medium|low",
 *   "assignTo": "gemini|claude|gpt|all"
 * }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $logs_dir = realpath(__DIR__ . '/../../logs');
    if (!$logs_dir) {
        $logs_dir = __DIR__ . '/../../logs';
        if (!is_dir($logs_dir)) {
            mkdir($logs_dir, 0755, true);
        }
    }

    // ============================================================
    // GET - Retornar tarefas criadas/status
    // ============================================================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $tasks_file = $logs_dir . '/monitor-tasks.jsonl';
        $tasks = [];

        if (file_exists($tasks_file)) {
            $lines = file($tasks_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $task = json_decode($line, true);
                if ($task) {
                    $tasks[] = $task;
                }
            }
        }

        // Retornar últimas 20 tarefas
        $tasks = array_reverse($tasks);
        $tasks = array_slice($tasks, 0, 20);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'tasks' => $tasks,
            'count' => count($tasks)
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // ============================================================
    // POST - Criar nova tarefa
    // ============================================================
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || !isset($data['title'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Title e action são obrigatórios']);
        exit;
    }

    $action = $data['action'] ?? 'create_task';
    $title = trim($data['title']);
    $description = $data['description'] ?? '';
    $priority = $data['priority'] ?? 'medium';
    $assignTo = $data['assignTo'] ?? 'all';

    if (empty($title)) {
        http_response_code(400);
        echo json_encode(['error' => 'Título vazio']);
        exit;
    }

    // Criar tarefa
    $task = [
        'id' => 'task_' . uniqid(),
        'timestamp' => date('c'),
        'action' => $action,
        'title' => $title,
        'description' => $description,
        'priority' => $priority,
        'assign_to' => $assignTo,
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s')
    ];

    // Salvar em JSONL (append)
    $tasks_file = $logs_dir . '/monitor-tasks.jsonl';
    $log_entry = json_encode($task, JSON_UNESCAPED_UNICODE) . "\n";

    if (!file_put_contents($tasks_file, $log_entry, FILE_APPEND | LOCK_EX)) {
        http_response_code(500);
        echo json_encode(['error' => 'Não foi possível salvar tarefa']);
        exit;
    }

    // Também adicionar à fila de tarefas para os agentes (tasks-queue.json)
    $queue_file = __DIR__ . '/../../logs/tasks-queue.json';
    $queue = [];

    if (file_exists($queue_file)) {
        $existing = json_decode((string)file_get_contents($queue_file), true);
        $queue = isset($existing['queue']) && is_array($existing['queue']) ? $existing['queue'] : (is_array($existing) ? $existing : []);
    }

    // Converter para formato de fila
    $queue_task = [
        'id' => $task['id'],
        'title' => $title,
        'description' => $description,
        'priority' => $priority,
        'assigned_agent' => $assignTo,
        'status' => 'pending',
        'created_at' => $task['timestamp']
    ];

    $queue[] = $queue_task;

    // Limitar a 50 tarefas na fila
    if (count($queue) > 50) {
        $queue = array_slice($queue, -50);
    }

    @mkdir(dirname($queue_file), 0755, true);
    file_put_contents($queue_file, json_encode(['queue' => $queue], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'task' => $task,
        'message' => 'Tarefa criada e enviada aos agentes',
        'note' => 'Agentes começarão a trabalhar em poucos minutos'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    error_log('Tasks command error: ' . $e->getMessage());
    echo json_encode(['error' => 'Erro interno']);
}
