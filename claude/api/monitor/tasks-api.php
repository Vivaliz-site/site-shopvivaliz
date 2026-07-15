<?php
/**
 * Tasks API - Gerenciar e listar tarefas
 * Endpoints para obter status de tarefas
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = $_GET['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            listTasks();
            break;
        case 'summary':
            taskSummary();
            break;
        case 'get-log':
            getTaskLog();
            break;
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Action not found']);
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log('Tasks API error: ' . $e->getMessage());
    echo json_encode(['error' => 'Erro interno']);
}

// Listar todas as tarefas com status
function listTasks() {
    $queue_file = __DIR__ . '/../../logs/tasks-queue.json';
    if (!file_exists($queue_file)) {
        http_response_code(404);
        echo json_encode(['error' => 'Fila de tarefas não encontrada']);
        exit;
    }

    $filter = $_GET['filter'] ?? 'all';
    $data = json_decode((string)file_get_contents($queue_file), true) ?: [];
    $tasks = $data['queue'] ?? $data;

    // Filtrar por status
    if ($filter !== 'all') {
        $tasks = array_filter($tasks, function($task) use ($filter) {
            return $task['status'] === $filter;
        });
    }

    // Ordenar por data de conclusão (mais recentes primeiro)
    usort($tasks, function($a, $b) {
        $a_time = strtotime($a['completed_at'] ?? $a['created_at'] ?? 0);
        $b_time = strtotime($b['completed_at'] ?? $b['created_at'] ?? 0);
        return $b_time - $a_time;
    });

    // Adicionar informações de log
    $log_dir = __DIR__ . '/../../logs/execution';
    foreach ($tasks as &$task) {
        $log_file = $log_dir . '/' . $task['id'] . '.log';
        $task['has_log'] = file_exists($log_file);
        $task['log_size'] = $task['has_log'] ? filesize($log_file) : 0;
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'tasks' => $tasks,
        'total' => count($data['queue']),
        'completed' => count(array_filter($data['queue'], function($t) { return $t['status'] === 'completed'; })),
        'pending' => count(array_filter($data['queue'], function($t) { return $t['status'] === 'pending'; }))
    ]);
}

// Resumo de tarefas
function taskSummary() {
    $queue_file = __DIR__ . '/../../logs/tasks-queue.json';
    if (!file_exists($queue_file)) {
        http_response_code(404);
        echo json_encode(['error' => 'Fila não encontrada']);
        exit;
    }

    $data = json_decode((string)file_get_contents($queue_file), true) ?: [];
    $queue = $data['queue'] ?? $data ?? [];

    $completed = array_filter($queue, function($t) { return $t['status'] === 'completed'; });
    $pending = array_filter($queue, function($t) { return $t['status'] === 'pending'; });

    $total = count($queue);
    $completed_count = count($completed);
    $percentage = $total > 0 ? round(($completed_count / $total) * 100, 1) : 0;

    // Calcular tempo médio de conclusão
    $completion_times = [];
    foreach ($completed as $task) {
        if (isset($task['created_at']) && isset($task['completed_at'])) {
            $created = strtotime($task['created_at']);
            $completed = strtotime($task['completed_at']);
            if ($created && $completed) {
                $completion_times[] = $completed - $created;
            }
        }
    }

    $avg_time = count($completion_times) > 0 ? round(array_sum($completion_times) / count($completion_times)) : 0;

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'total' => $total,
        'completed' => $completed_count,
        'pending' => count($pending),
        'percentage' => $percentage,
        'average_completion_time_seconds' => $avg_time,
        'average_completion_time_human' => formatTime($avg_time)
    ]);
}

// Obter log de uma tarefa específica
function getTaskLog() {
    $task_id = $_GET['task_id'] ?? null;
    if (!$task_id) {
        http_response_code(400);
        echo json_encode(['error' => 'task_id não fornecido']);
        exit;
    }

    $log_file = __DIR__ . '/../../logs/execution/' . $task_id . '.log';
    if (!file_exists($log_file)) {
        http_response_code(404);
        echo json_encode(['error' => 'Log não encontrado']);
        exit;
    }

    $content = file_get_contents($log_file);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'task_id' => $task_id,
        'log_content' => $content,
        'file_size' => filesize($log_file),
        'last_modified' => date('c', filemtime($log_file))
    ]);
}

// Helper function
function formatTime($seconds) {
    if ($seconds < 60) {
        return $seconds . 's';
    } elseif ($seconds < 3600) {
        return round($seconds / 60) . 'min';
    } else {
        return round($seconds / 3600) . 'h';
    }
}
