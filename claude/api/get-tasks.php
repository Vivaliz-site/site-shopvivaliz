<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$tasks_file = __DIR__ . '/../logs/tasks-queue.json';

if (!file_exists($tasks_file)) {
    http_response_code(404);
    echo json_encode(['error' => 'Arquivo de tarefas não encontrado']);
    exit;
}

$tasks = json_decode(file_get_contents($tasks_file), true);

if (!$tasks) {
    http_response_code(200);
    echo json_encode([]);
    exit;
}

http_response_code(200);
echo json_encode($tasks, JSON_UNESCAPED_UNICODE);
