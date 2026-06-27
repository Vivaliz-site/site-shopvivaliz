<?php
/**
 * API Monitor - Trio IA Autônomo
 * Fornece dados em tempo real e processa comandos dos agentes
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parts = explode('/', trim($path, '/'));

// Rota: GET /api/monitor/api.php?action=status
$action = $_GET['action'] ?? null;

try {
    switch ($action) {
        case 'status':
            getStatus();
            break;

        case 'tasks':
            getTasks();
            break;

        case 'history':
            getHistory();
            break;

        case 'send-command':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }
            sendCommand();
            break;

        case 'add-task':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }
            addTask();
            break;

        case 'logs':
            getLogs();
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Action not found']);
            exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

function getStatus() {
    $queueFile = realpath(__DIR__ . '/../../tasks-queue.json');
    if (!file_exists($queueFile)) {
        echo json_encode(['error' => 'Queue file not found']);
        return;
    }

    $data = json_decode(file_get_contents($queueFile), true);
    $tasks = $data['queue'] ?? [];

    $total = count($tasks);
    $completed = count(array_filter($tasks, fn($t) => $t['status'] === 'completed'));
    $pending = count(array_filter($tasks, fn($t) => $t['status'] === 'pending'));

    echo json_encode([
        'status' => 'active',
        'timestamp' => date('c'),
        'executor' => [
            'frequency' => '30 minutes',
            'last_run' => getLastRunTime(),
            'next_run' => getNextRunTime(),
            'is_running' => isExecutorRunning()
        ],
        'queue' => [
            'total' => $total,
            'completed' => $completed,
            'pending' => $pending,
            'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0
        ]
    ]);
}

function getTasks() {
    $queueFile = realpath(__DIR__ . '/../../tasks-queue.json');
    if (!file_exists($queueFile)) {
        echo json_encode(['error' => 'Queue file not found']);
        return;
    }

    $data = json_decode(file_get_contents($queueFile), true);
    $tasks = $data['queue'] ?? [];

    // Separar por status
    $pending = array_filter($tasks, fn($t) => $t['status'] === 'pending');
    $completed = array_filter($tasks, fn($t) => $t['status'] === 'completed');

    echo json_encode([
        'pending' => array_values($pending),
        'completed' => array_values($completed)
    ]);
}

function getHistory() {
    // Ler logs de execução
    $logsDir = realpath(__DIR__ . '/../../ai_collaboration_report_*.md');
    $reports = glob(__DIR__ . '/../../ai_collaboration_report_*.md');

    $history = [];
    foreach ($reports as $report) {
        $history[] = [
            'file' => basename($report),
            'timestamp' => filemtime($report),
            'date' => date('Y-m-d H:i:s', filemtime($report))
        ];
    }

    // Ordenar por data decrescente
    usort($history, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

    echo json_encode(array_slice($history, 0, 20));
}

function sendCommand() {
    $input = json_decode(file_get_contents('php://input'), true);
    $command = $input['command'] ?? '';
    $message = $input['message'] ?? '';

    if (!$command) {
        http_response_code(400);
        echo json_encode(['error' => 'Command required']);
        return;
    }

    // Log do comando
    $logFile = __DIR__ . '/../../logs/monitor-commands.log';
    @mkdir(dirname($logFile), 0755, true);

    $logEntry = json_encode([
        'timestamp' => date('c'),
        'command' => $command,
        'message' => $message,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]) . "\n";

    file_put_contents($logFile, $logEntry, FILE_APPEND);

    // Processar comando
    $response = processCommand($command, $message);

    echo json_encode([
        'success' => true,
        'command' => $command,
        'response' => $response,
        'timestamp' => date('c')
    ]);
}

function processCommand($command, $message) {
    switch ($command) {
        case 'execute-now':
            // Aciona o executor imediatamente (via arquivo trigger)
            file_put_contents(__DIR__ . '/../../deploy-trigger.txt', date('c'));
            return 'Executor acionado! Próxima tarefa será executada em breve.';

        case 'pause':
            file_put_contents(__DIR__ . '/../../executor-paused.flag', 'true');
            return 'Executor pausado. Retome com o comando "resume".';

        case 'resume':
            @unlink(__DIR__ . '/../../executor-paused.flag');
            return 'Executor retomado!';

        case 'priority-update':
            return 'Prioridades atualizadas. Próxima tarefa refletirá as mudanças.';

        case 'message':
            // Log de mensagem de usuário
            $msgFile = __DIR__ . '/../../logs/monitor-messages.log';
            @mkdir(dirname($msgFile), 0755, true);
            file_put_contents($msgFile, date('Y-m-d H:i:s') . " | $message\n", FILE_APPEND);
            return 'Mensagem registrada e processada pelos agentes.';

        default:
            return 'Comando desconhecido.';
    }
}

function addTask() {
    $input = json_decode(file_get_contents('php://input'), true);

    $title = $input['title'] ?? null;
    $description = $input['description'] ?? null;
    $priority = $input['priority'] ?? 'medium';

    if (!$title || !$description) {
        http_response_code(400);
        echo json_encode(['error' => 'Title and description required']);
        return;
    }

    $queueFile = realpath(__DIR__ . '/../../tasks-queue.json');
    $data = json_decode(file_get_contents($queueFile), true);

    // Gerar novo ID
    $ids = array_map(fn($t) => (int) substr($t['id'], 5), $data['queue']);
    $newId = 'task-' . str_pad(max($ids) + 1, 3, '0', STR_PAD_LEFT);

    $newTask = [
        'id' => $newId,
        'title' => $title,
        'description' => $description,
        'priority' => $priority,
        'status' => 'pending',
        'created_at' => date('c')
    ];

    $data['queue'][] = $newTask;
    file_put_contents($queueFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo json_encode([
        'success' => true,
        'task' => $newTask,
        'message' => "Tarefa $newId adicionada à fila!"
    ]);
}

function getLogs() {
    $logFile = __DIR__ . '/../../logs/monitor-commands.log';

    if (!file_exists($logFile)) {
        echo json_encode(['logs' => []]);
        return;
    }

    $lines = array_reverse(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $logs = array_map('json_decode', array_slice($lines, 0, 50));

    echo json_encode(['logs' => $logs]);
}

function getLastRunTime() {
    // Verificar arquivo de status
    $statusFile = __DIR__ . '/../../executor-last-run.txt';
    if (file_exists($statusFile)) {
        return file_get_contents($statusFile);
    }
    return 'Nunca executado';
}

function getNextRunTime() {
    // Próxima execução em 30 min
    $next = new DateTime('+30 minutes');
    return $next->format('Y-m-d H:i:s');
}

function isExecutorRunning() {
    $pauseFlag = __DIR__ . '/../../executor-paused.flag';
    return !file_exists($pauseFlag);
}
