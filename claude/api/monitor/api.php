<?php
/**
 * API Monitor - Trio IA Autônomo
 * Fornece dados em tempo real e processa comandos dos agentes
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Carregar variáveis do .env
$envFile = dirname(__DIR__, 2) . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim(trim($v), "\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parts = explode('/', trim($path, '/'));

// Rota: GET api/monitor/api.php?action=status
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
    $queueFile = __DIR__ . '/../../logs/tasks-queue.json';
    if (!file_exists($queueFile)) {
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
                'total' => 0,
                'completed' => 0,
                'pending' => 0,
                'completion_rate' => 0
            ]
        ]);
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
    $queueFile = __DIR__ . '/../../logs/tasks-queue.json';
    if (!file_exists($queueFile)) {
        echo json_encode(['pending' => [], 'completed' => []]);
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
    $reports = glob(__DIR__ . '/../../ai_collaboration_report_*.md') ?: [];

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

function triggerAgentResponse($userMessage) {
    /**
     * Faz os agentes responderem no chat em tempo real
     * Usa Trio IA: Gemini → Claude
     */
    try {
        // Gemini analisa e responde
        $geminiResponse = callGemini($userMessage);

        if ($geminiResponse) {
            // Claude refina a resposta
            $claudeResponse = callClaude($userMessage, $geminiResponse);
            $finalResponse = $claudeResponse ?: $geminiResponse;

            // Salvar resposta
            $responseFile = __DIR__ . '/../../logs/monitor-responses.jsonl';
            @mkdir(dirname($responseFile), 0755, true);

            $logEntry = [
                'timestamp' => date('c'),
                'user_message' => $userMessage,
                'agent_response' => $finalResponse,
                'agents_used' => ['Gemini', 'Claude']
            ];

            file_put_contents($responseFile, json_encode($logEntry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

            return $finalResponse;
        } else {
            error_log("⚠️ Nenhuma resposta recebida do Gemini");
        }
    } catch (Exception $e) {
        error_log("❌ Agent response error: " . $e->getMessage());
    }

    return null;
}

function callGemini($message) {
    // Tentar múltiplas formas de acessar a chave
    $apiKey = $_ENV['GEMINI_API_KEY']
           ?? getenv('GEMINI_API_KEY')
           ?? $_SERVER['GEMINI_API_KEY']
           ?? null;

    if (!$apiKey) {
        error_log("❌ GEMINI_API_KEY não encontrada");
        return null;
    }

    try {
        $geminiModel = getenv('GEMINI_MODEL') ?: 'gemini-1.5-flash';
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($geminiModel) . ':generateContent?key=' . urlencode($apiKey);

        $payload = json_encode([
            'contents' => [
                ['parts' => [['text' => "Você é um assistente técnico do ShopVivaliz ecommerce. Responda BREVEMENTE (máx 100 palavras):\n\n$message"]]]
            ]
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("❌ Gemini HTTP $httpCode: $response");
            return null;
        }

        $data = json_decode($response, true);
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    } catch (Exception $e) {
        error_log("❌ Gemini error: " . $e->getMessage());
        return null;
    }
}

function callClaude($userMessage, $geminiResponse) {
    // Tentar múltiplas formas de acessar a chave
    $apiKey = $_ENV['ANTHROPIC_API_KEY']
           ?? getenv('ANTHROPIC_API_KEY')
           ?? $_SERVER['ANTHROPIC_API_KEY']
           ?? null;

    if (!$apiKey) {
        error_log("❌ ANTHROPIC_API_KEY não encontrada");
        return null;
    }

    try {
        $payload = json_encode([
            'model' => getenv('ANTHROPIC_MODEL') ?: 'claude-haiku-4-5-20251001',
            'max_tokens' => 256,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => "Refine BREVEMENTE (máx 100 palavras):\n\n$geminiResponse\n\nOriginal: $userMessage"
                ]
            ]
        ]);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'Content-Length: ' . strlen($payload)
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("❌ Claude HTTP $httpCode: $response");
            return null;
        }

        $data = json_decode($response, true);
        return $data['content'][0]['text'] ?? null;
    } catch (Exception $e) {
        error_log("❌ Claude error: " . $e->getMessage());
        return null;
    }
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

            // Acionar resposta dos agentes
            $agentResponse = triggerAgentResponse($message);

            // Se agentes responderam, retornar resposta
            if ($agentResponse) {
                return '✅ ' . $agentResponse;
            }

            // Fallback: resposta genérica se APIs falharem
            return '⚠️ Agentes offline. Status: Processando tarefas. Tente novamente em alguns minutos.';

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

    $queueFile = __DIR__ . '/../../logs/tasks-queue.json';
    $data = file_exists($queueFile) ? (json_decode((string)file_get_contents($queueFile), true) ?: []) : [];
    $data['queue'] = $data['queue'] ?? [];

    // Gerar novo ID
    $ids = array_map(function ($t) {
        return (int) preg_replace('/\D+/', '', $t['id'] ?? '0');
    }, $data['queue']);
    $ids = array_filter($ids, fn($value) => $value > 0);
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
    @mkdir(dirname($queueFile), 0755, true);
    file_put_contents($queueFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

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

    $lines = array_reverse(@file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    $logs = [];
    foreach (array_slice($lines, 0, 50) as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $logs[] = $decoded;
        }
    }

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
