<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap-env.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function monitor_root(): string
{
    return dirname(__DIR__, 2);
}

function monitor_logs_dir(): string
{
    return monitor_root() . '/logs';
}

function monitor_storage_dir(): string
{
    return monitor_root() . '/storage/private';
}

function monitor_read_json(string $relPath, array $fallback = []): array
{
    $path = monitor_root() . '/' . ltrim($relPath, '/');
    if (!is_file($path)) {
        return $fallback;
    }

    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : $fallback;
}

function monitor_file_age(string $relPath): ?int
{
    $path = monitor_root() . '/' . ltrim($relPath, '/');
    return is_file($path) ? (int)(time() - filemtime($path)) : null;
}

function monitor_read_first_json(array $relPaths, array $fallback = []): array
{
    foreach ($relPaths as $relPath) {
        $data = monitor_read_json($relPath, []);
        if ($data !== []) {
            return $data;
        }
    }

    return $fallback;
}

function monitor_queue_candidates(): array
{
    return [
        'tasks-queue.json',
        'logs/tasks-queue.json',
    ];
}

function monitor_normalize_external_task(array $task): array
{
    return [
        'id' => $task['id'] ?? $task['task_id'] ?? null,
        'task_id' => $task['task_id'] ?? $task['id'] ?? null,
        'title' => $task['title'] ?? $task['action'] ?? 'Tarefa sem titulo',
        'description' => $task['description'] ?? '',
        'priority' => $task['priority'] ?? 'medium',
        'status' => $task['status'] ?? 'pending',
        'assigned_to' => $task['assigned_to'] ?? [],
        'requires_secrets' => $task['requires_secrets'] ?? [],
        'type' => $task['type'] ?? null,
        'action' => $task['action'] ?? null,
        'created_at' => $task['created_at'] ?? null,
        'source_schema' => 'metadata_tasks',
    ];
}

function monitor_read_queue(): array
{
    foreach (monitor_queue_candidates() as $relPath) {
        $data = monitor_read_json($relPath, []);
        if ($data === []) {
            continue;
        }

        if (array_is_list($data)) {
            return ['tasks' => $data, 'source' => $relPath];
        }

        $queue = $data['queue'] ?? null;
        if (is_array($queue) && array_is_list($queue)) {
            return ['tasks' => $queue, 'source' => $relPath];
        }

        $tasks = $data['tasks'] ?? null;
        if (is_array($tasks) && array_is_list($tasks)) {
            return [
                'tasks' => array_map('monitor_normalize_external_task', $tasks),
                'source' => $relPath,
            ];
        }
    }

    return ['tasks' => [], 'source' => null];
}

function monitor_count_tasks(array $tasks, array $statuses): int
{
    return count(array_filter($tasks, static function ($task) use ($statuses): bool {
        $status = strtolower((string)($task['status'] ?? ''));
        return in_array($status, $statuses, true);
    }));
}

function monitor_jsonl_tail(string $path, int $limit = 50): array
{
    if (!is_file($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $lines = array_slice($lines, -$limit);
    $items = [];
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $items[] = $row;
        }
    }
    return $items;
}

function monitor_agent_ids(): array
{
    return ['claude', 'gemini', 'gpt'];
}

function monitor_agent_labels(): array
{
    return [
        'claude' => 'Claude',
        'gemini' => 'Gemini',
        'gpt' => 'ChatGPT',
    ];
}

function monitor_agent_roles(): array
{
    return [
        'claude' => 'Implementacao e codigo',
        'gemini' => 'Arquitetura e descoberta',
        'gpt' => 'Validacao e QA',
    ];
}

function monitor_heartbeat_status(): array
{
    $dir = monitor_root() . '/.agent-heartbeats';
    $ttl = 900;
    $status = [];

    foreach (monitor_agent_ids() as $agentId) {
        $file = $dir . '/' . $agentId . '.heartbeat';
        $payload = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
        $payload = is_array($payload) ? $payload : [];
        $age = is_file($file) ? (time() - filemtime($file)) : null;

        $status[$agentId] = [
            'alive' => $age !== null ? $age < $ttl : false,
            'age_s' => $age,
            'last_heartbeat' => $payload['timestamp'] ?? null,
            'tasks_processed' => (int)($payload['tasks_processed'] ?? 0),
        ];
    }

    return $status;
}

function monitor_agent_commands_file(): string
{
    return monitor_storage_dir() . '/agent-commands.jsonl';
}

function monitor_agent_messages_file(): string
{
    return monitor_logs_dir() . '/monitor-messages.log';
}

function monitor_agent_responses_file(): string
{
    return monitor_logs_dir() . '/monitor-responses.jsonl';
}

function monitor_ensure_dirs(): void
{
    @mkdir(monitor_logs_dir(), 0755, true);
    @mkdir(monitor_storage_dir(), 0755, true);
}

function monitor_write_jsonl(string $path, array $payload): void
{
    monitor_ensure_dirs();
    file_put_contents(
        $path,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function monitor_current_cycle_task(array $cycle): ?array
{
    $selectionTask = $cycle['selection']['task'] ?? null;
    if (is_array($selectionTask)) {
        return $selectionTask;
    }
    $task = $cycle['task'] ?? null;
    return is_array($task) ? $task : null;
}

function monitor_filter_by_agent(array $rows, string $agentId, array $aliases = []): array
{
    return array_values(array_filter($rows, static function (array $row) use ($agentId, $aliases): bool {
        $value = strtolower((string)($row['agent_id'] ?? $row['agent'] ?? ''));
        if ($value === $agentId) {
            return true;
        }
        return in_array($value, $aliases, true);
    }));
}

function monitor_agent_activity(array $tasks, array $cycle): array
{
    $labels = monitor_agent_labels();
    $roles = monitor_agent_roles();
    $heartbeats = monitor_heartbeat_status();
    $responses = monitor_jsonl_tail(monitor_agent_responses_file(), 100);
    $messages = monitor_jsonl_tail(monitor_agent_messages_file(), 100);
    $commands = monitor_jsonl_tail(monitor_agent_commands_file(), 100);
    $currentTask = monitor_current_cycle_task($cycle);

    $agents = [];
    foreach (monitor_agent_ids() as $agentId) {
        $aliases = [strtolower((string)($labels[$agentId] ?? ''))];
        $assignedTasks = array_values(array_filter($tasks, static function (array $task) use ($agentId): bool {
            $assigned = $task['assigned_to'] ?? null;
            if (is_string($assigned)) {
                return strtolower($assigned) === $agentId;
            }
            if (is_array($assigned)) {
                $normalized = array_map(static fn($value): string => strtolower((string)$value), $assigned);
                return in_array($agentId, $normalized, true);
            }
            return false;
        }));

        $agentCommands = monitor_filter_by_agent($commands, $agentId, $aliases);
        $agentMessages = monitor_filter_by_agent($messages, $agentId, $aliases);
        $agentResponses = monitor_filter_by_agent($responses, $agentId, $aliases);

        $agents[] = [
            'id' => $agentId,
            'name' => $labels[$agentId] ?? strtoupper($agentId),
            'role' => $roles[$agentId] ?? 'Agente autonomo',
            'heartbeat' => $heartbeats[$agentId] ?? ['alive' => false, 'age_s' => null, 'last_heartbeat' => null, 'tasks_processed' => 0],
            'assigned_tasks' => array_slice($assignedTasks, 0, 5),
            'current_focus' => $assignedTasks[0]['title'] ?? ($currentTask['title'] ?? 'Aguardando tarefa'),
            'command_backlog' => count(array_filter($agentCommands, static fn(array $row): bool => (($row['status'] ?? 'queued') === 'queued'))),
            'latest_command' => $agentCommands ? $agentCommands[array_key_last($agentCommands)] : null,
            'latest_message' => $agentMessages ? $agentMessages[array_key_last($agentMessages)] : null,
            'latest_response' => $agentResponses ? $agentResponses[array_key_last($agentResponses)] : null,
        ];
    }

    return $agents;
}

function monitor_generate_tasks(): array
{
    $root = monitor_root();
    $python = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
    $command = escapeshellcmd($python) . ' ' . escapeshellarg($root . '/scripts/auto-task-generator.py');
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    return [
        'ok' => $exitCode === 0,
        'exit_code' => $exitCode,
        'output' => $output,
    ];
}

function getStatus(): array
{
    $action = $_GET['action'] ?? 'status';
    $triSync = monitor_read_first_json([
        'logs/tri-environment-sync.json',
        'logs/autonomous-sync.json',
    ], []);
    $cycle = monitor_read_json('logs/autonomous-cycle-report.json', []);
    $queueState = monitor_read_queue();
    $tasks = $queueState['tasks'];
    $queueSource = $queueState['source'];

    $triStatus = strtolower((string)($triSync['status'] ?? 'unknown'));
    $isRunning = $triStatus === 'healthy' || $triStatus === 'warning';
    $pendingCount = monitor_count_tasks($tasks, ['pending']);
    $activeCount = monitor_count_tasks($tasks, ['assigned', 'running', 'in_progress']);
    $completedCount = monitor_count_tasks($tasks, ['completed', 'done']);

    switch ($action) {
        case 'status':
            return [
                'status' => 'ok',
                'message' => 'Monitor operacional com sincronizacao triambiente',
                'autonomous_status' => [
                    'is_running' => $isRunning,
                    'last_cycle_seconds_ago' => monitor_file_age('logs/tri-environment-sync.json'),
                    'status' => $triStatus === 'healthy' ? 'healthy' : ($triStatus === 'warning' ? 'warning' : 'critical'),
                ],
                'tri_environment_sync' => [
                    'environment' => $triSync['environment'] ?? null,
                    'branch' => $triSync['git']['branch'] ?? null,
                    'ahead_by' => $triSync['git']['ahead_by'] ?? null,
                    'behind_by' => $triSync['git']['behind_by'] ?? null,
                    'dirty_count' => $triSync['git']['dirty_count'] ?? null,
                    'next_action' => $triSync['nextAction'] ?? null,
                    'status' => $triStatus,
                ],
                'queue' => [
                    'source' => $queueSource,
                    'source_file_age_s' => $queueSource ? monitor_file_age($queueSource) : null,
                    'total' => count($tasks),
                    'pending' => $pendingCount,
                    'active' => $activeCount,
                    'completed' => $completedCount,
                ],
                'details' => [
                    'cycle_status' => $cycle['status'] ?? null,
                    'cycle_generated_at' => $cycle['generated_at'] ?? null,
                    'tasks_total' => count($tasks),
                ],
            ];

        case 'agents':
            return [
                'status' => 'ok',
                'message' => 'Agentes carregados',
                'generated_at' => date('c'),
                'queue_source' => $queueSource,
                'agents' => monitor_agent_activity($tasks, $cycle),
            ];

        case 'messages':
            return [
                'status' => 'ok',
                'message' => 'Mensagens dos agentes carregadas',
                'commands' => monitor_jsonl_tail(monitor_agent_commands_file(), 100),
                'messages' => monitor_jsonl_tail(monitor_agent_messages_file(), 100),
                'responses' => monitor_jsonl_tail(monitor_agent_responses_file(), 100),
            ];

        case 'send-command':
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                http_response_code(405);
                return ['status' => 'error', 'message' => 'Use POST para enviar comandos.'];
            }

            $payload = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($payload)) {
                $payload = $_POST;
            }

            $agentId = strtolower(trim((string)($payload['agent_id'] ?? '')));
            $message = trim((string)($payload['message'] ?? ''));
            if ($agentId === '' || !in_array($agentId, monitor_agent_ids(), true) || $message === '') {
                http_response_code(422);
                return ['status' => 'error', 'message' => 'agent_id e message sao obrigatorios.'];
            }

            $entry = [
                'id' => bin2hex(random_bytes(8)),
                'agent_id' => $agentId,
                'message' => $message,
                'source' => trim((string)($payload['source'] ?? 'admin-monitor')),
                'status' => 'queued',
                'created_at' => date('c'),
            ];
            monitor_write_jsonl(monitor_agent_commands_file(), $entry);
            monitor_write_jsonl(monitor_agent_messages_file(), $entry);

            return [
                'status' => 'ok',
                'message' => 'Comando enfileirado para o agente.',
                'command' => $entry,
            ];

        case 'generate-tasks':
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                http_response_code(405);
                return ['status' => 'error', 'message' => 'Use POST para gerar tarefas.'];
            }
            $execution = monitor_generate_tasks();
            return [
                'status' => $execution['ok'] ? 'ok' : 'error',
                'message' => $execution['ok'] ? 'Geracao de tarefas executada.' : 'Falha ao gerar tarefas.',
                'execution' => $execution,
            ];

        case 'tasks':
            return [
                'status' => 'ok',
                'message' => 'Tasks API conectada a fila canonica',
                'source' => $queueSource,
                'tasks' => array_slice($tasks, 0, 50),
                'queue_file_age_s' => $queueSource ? monitor_file_age($queueSource) : null,
            ];

        case 'logs':
            return [
                'status' => 'ok',
                'message' => 'Logs API conectada ao rastro autonomo',
                'logs' => [
                    'tri_environment_sync' => $triSync,
                    'autonomous_cycle_report' => monitor_read_json('logs/autonomous-cycle-report.json', []),
                    'queue_source' => $queueSource,
                ],
            ];

        default:
            http_response_code(400);
            return ['status' => 'error', 'message' => 'Acao desconhecida.'];
    }
}

$response = getStatus();
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
