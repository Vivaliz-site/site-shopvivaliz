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

function monitor_read_jsonl(string $relPath, int $limit = 200): array
{
    $path = monitor_root() . '/' . ltrim($relPath, '/');
    if (!is_file($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $items = [];
    foreach (array_slice($lines, -$limit) as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $items[] = $row;
        }
    }
    return $items;
}

function monitor_write_jsonl(string $relPath, array $payload): void
{
    $path = monitor_root() . '/' . ltrim($relPath, '/');
    @mkdir(dirname($path), 0755, true);
    file_put_contents(
        $path,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function monitor_queue_candidates(): array
{
    return ['tasks-queue.json', 'logs/tasks-queue.json'];
}

function monitor_normalize_task(array $task): array
{
    return [
        'id' => $task['id'] ?? $task['task_id'] ?? null,
        'title' => $task['title'] ?? $task['action'] ?? 'Tarefa sem titulo',
        'description' => $task['description'] ?? '',
        'priority' => $task['priority'] ?? 'medium',
        'status' => $task['status'] ?? 'pending',
        'assigned_to' => $task['assigned_to'] ?? [],
        'action' => $task['action'] ?? null,
        'type' => $task['type'] ?? null,
        'created_at' => $task['created_at'] ?? null,
        'started_at' => $task['started_at'] ?? null,
        'finished_at' => $task['finished_at'] ?? null,
        'metadata' => $task['metadata'] ?? [],
        'last_result' => $task['last_result'] ?? null,
    ];
}

function monitor_read_queue(): array
{
    foreach (monitor_queue_candidates() as $relPath) {
        $payload = monitor_read_json($relPath, []);
        if ($payload === []) {
            continue;
        }

        if (isset($payload['queue']) && is_array($payload['queue'])) {
            return [
                'source' => $relPath,
                'tasks' => array_map('monitor_normalize_task', $payload['queue']),
            ];
        }

        if (isset($payload['tasks']) && is_array($payload['tasks'])) {
            return [
                'source' => $relPath,
                'tasks' => array_map('monitor_normalize_task', $payload['tasks']),
            ];
        }
    }

    return ['source' => null, 'tasks' => []];
}

function monitor_file_age(string $relPath): ?int
{
    $path = monitor_root() . '/' . ltrim($relPath, '/');
    return is_file($path) ? (int) (time() - filemtime($path)) : null;
}

function monitor_agent_ids(): array
{
    return ['core-agent', 'claude', 'gemini', 'gpt'];
}

function monitor_agent_labels(): array
{
    return [
        'core-agent' => 'Core-Agent',
        'claude' => 'Claude',
        'gemini' => 'Gemini',
        'gpt' => 'ChatGPT',
    ];
}

function monitor_heartbeat_status(): array
{
    $dir = monitor_root() . '/.agent-heartbeats';
    $ttl = 900;
    $result = [];
    foreach (['claude', 'gemini', 'gpt'] as $agentId) {
        $path = $dir . '/' . $agentId . '.heartbeat';
        $payload = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $payload = is_array($payload) ? $payload : [];
        $age = is_file($path) ? (time() - filemtime($path)) : null;
        $result[$agentId] = [
            'alive' => $age !== null ? $age < $ttl : false,
            'age_s' => $age,
            'last_heartbeat' => $payload['timestamp'] ?? null,
            'tasks_processed' => (int) ($payload['tasks_processed'] ?? 0),
            'current_focus' => $payload['current_focus'] ?? 'Aguardando tarefa',
            'passos_execucao' => array_slice((array) ($payload['passos_execucao'] ?? []), -10),
        ];
    }
    return $result;
}

function monitor_filter_steps(array $rows, string $agentId): array
{
    return array_values(array_filter($rows, static function (array $row) use ($agentId): bool {
        return strtolower((string) ($row['agent_id'] ?? '')) === $agentId;
    }));
}

function monitor_agent_status_color(string $status, ?bool $alive = true): string
{
    $normalized = strtolower($status);
    if (in_array($normalized, ['error', 'failed'], true)) {
        return 'red';
    }
    if (in_array($normalized, ['waiting_feedback', 'paused', 'idle'], true)) {
        return 'yellow';
    }
    if ($alive === false) {
        return 'red';
    }
    return 'green';
}

function monitor_agent_activity(array $tasks): array
{
    $labels = monitor_agent_labels();
    $heartbeats = monitor_heartbeat_status();
    $runtimeState = monitor_read_json('logs/agent-runtime-state.json', []);
    $steps = monitor_read_jsonl('logs/autonomous/agent-steps.jsonl', 300);
    $workerSteps = monitor_read_jsonl('logs/agent-execution-steps.jsonl', 300);
    $commands = monitor_read_jsonl('storage/private/agent-interventions.jsonl', 200);
    $responses = monitor_read_jsonl('storage/private/agent-intervention-responses.jsonl', 200);
    $liveStatus = monitor_read_json('logs/autonomous/live-status.json', []);

    $agents = [];
    foreach (monitor_agent_ids() as $agentId) {
        $agentSteps = array_merge(
            monitor_filter_steps($steps, $agentId),
            monitor_filter_steps($workerSteps, $agentId)
        );
        usort($agentSteps, static function (array $a, array $b): int {
            return strcmp((string) ($a['timestamp'] ?? ''), (string) ($b['timestamp'] ?? ''));
        });
        $latestStep = $agentSteps !== [] ? $agentSteps[array_key_last($agentSteps)] : [];
        $assignedTasks = array_values(array_filter($tasks, static function (array $task) use ($agentId): bool {
            $assigned = $task['assigned_to'] ?? [];
            if (is_string($assigned)) {
                return strtolower($assigned) === $agentId;
            }
            if (is_array($assigned)) {
                return in_array($agentId, array_map('strtolower', array_map('strval', $assigned)), true);
            }
            return false;
        }));

        $agentCommands = array_values(array_filter($commands, static function (array $row) use ($agentId): bool {
            return strtolower((string) ($row['agent_id'] ?? '')) === $agentId;
        }));
        $agentResponses = array_values(array_filter($responses, static function (array $row) use ($agentId): bool {
            return strtolower((string) ($row['agent_id'] ?? '')) === $agentId;
        }));
        $answeredIds = [];
        foreach ($agentResponses as $response) {
            $commandId = (string) ($response['command_id'] ?? '');
            if ($commandId !== '') {
                $answeredIds[$commandId] = true;
            }
        }

        $liveAgent = $liveStatus['agents'][$agentId] ?? [];
        $workerAgent = $runtimeState['agents'][$agentId] ?? [];
        $heartbeat = $heartbeats[$agentId] ?? [
            'alive' => $agentId === 'core-agent',
            'age_s' => monitor_file_age('logs/autonomous/live-status.json'),
            'last_heartbeat' => $liveStatus['generated_at'] ?? null,
            'tasks_processed' => 0,
            'current_focus' => 'Aguardando tarefa',
            'passos_execucao' => [],
        ];
        $status = (string) ($liveAgent['status'] ?? $latestStep['status'] ?? 'idle');
        $focus = (string) ($workerAgent['current_focus'] ?? $heartbeat['current_focus'] ?? $liveAgent['action'] ?? 'Aguardando tarefa');
        $passosExecucao = array_slice(
            $workerAgent['passos_execucao'] ?? $heartbeat['passos_execucao'] ?? [],
            -10
        );
        $agents[] = [
            'id' => $agentId,
            'name' => $labels[$agentId] ?? strtoupper($agentId),
            'role' => $liveAgent['role'] ?? ($agentId === 'core-agent' ? 'Orquestracao autonoma' : 'Agente autonomo'),
            'heartbeat' => $heartbeat,
            'status' => $status,
            'status_color' => monitor_agent_status_color($status, $heartbeat['alive'] ?? true),
            'current_action' => $liveAgent['action'] ?? ($latestStep['action'] ?? 'Aguardando trabalho'),
            'current_focus' => $focus,
            'assigned_tasks' => array_slice($assignedTasks, 0, 8),
            'command_backlog' => count(array_filter($agentCommands, static function (array $row) use ($answeredIds): bool {
                $commandId = (string) ($row['id'] ?? '');
                return $commandId !== '' && !isset($answeredIds[$commandId]);
            })),
            'latest_command' => $agentCommands !== [] ? $agentCommands[array_key_last($agentCommands)] : null,
            'latest_response' => $agentResponses !== [] ? $agentResponses[array_key_last($agentResponses)] : null,
            'steps' => array_slice($agentSteps, -12),
            'passos_execucao' => $passosExecucao,
        ];
    }

    return $agents;
}

function monitor_status_summary(array $tasks): array
{
    $pending = 0;
    $active = 0;
    $completed = 0;
    foreach ($tasks as $task) {
        $status = strtolower((string) ($task['status'] ?? 'pending'));
        if ($status === 'pending') {
            $pending++;
        } elseif (in_array($status, ['in_progress', 'running', 'assigned'], true)) {
            $active++;
        } elseif (in_array($status, ['completed', 'done', 'pr_opened'], true)) {
            $completed++;
        }
    }

    return [
        'total' => count($tasks),
        'pending' => $pending,
        'active' => $active,
        'completed' => $completed,
    ];
}

function monitor_send_operational_command(array $payload): array
{
    $agentId = strtolower(trim((string) ($payload['agent_id'] ?? '')));
    $message = trim((string) ($payload['message'] ?? ''));
    if ($agentId === '' || $message === '' || !in_array($agentId, monitor_agent_ids(), true)) {
        http_response_code(422);
        return ['status' => 'error', 'message' => 'agent_id e message sao obrigatorios.'];
    }

    $command = [
        'id' => bin2hex(random_bytes(8)),
        'agent_id' => $agentId,
        'message' => $message,
        'source' => trim((string) ($payload['source'] ?? 'admin-monitor')),
        'created_at' => date('c'),
        'status' => 'queued',
        'kind' => 'human-intervention',
    ];
    monitor_write_jsonl('storage/private/agent-interventions.jsonl', $command);

    return [
        'status' => 'ok',
        'message' => 'Intervencao operacional enviada ao agente.',
        'command' => $command,
    ];
}

function monitor_generate_tasks(): array
{
    $root = monitor_root();
    $python = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
    $command = escapeshellcmd($python) . ' ' . escapeshellarg($root . '/scripts/autonomous-executor.py') . ' --max-cycles 1';
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    return [
        'ok' => $exitCode === 0,
        'exit_code' => $exitCode,
        'output' => $output,
    ];
}

function monitor_response(): array
{
    $action = $_GET['action'] ?? 'status';
    $queueState = monitor_read_queue();
    $tasks = $queueState['tasks'];
    $summary = monitor_status_summary($tasks);
    $cycle = monitor_read_json('logs/autonomous-cycle-report.json', []);
    $live = monitor_read_json('logs/autonomous/live-status.json', []);

    switch ($action) {
        case 'status':
            return [
                'status' => 'ok',
                'generated_at' => date('c'),
                'queue' => array_merge($summary, [
                    'source' => $queueState['source'],
                    'source_file_age_s' => $queueState['source'] ? monitor_file_age($queueState['source']) : null,
                ]),
                'details' => [
                    'cycle_status' => $cycle['status'] ?? 'unknown',
                    'cycle_generated_at' => $cycle['generated_at'] ?? null,
                    'generated_tasks' => $cycle['generated_tasks'] ?? [],
                ],
                'autonomous_status' => [
                    'status' => $summary['active'] > 0 || $summary['pending'] > 0 ? 'healthy' : 'warning',
                    'is_running' => true,
                    'last_cycle_seconds_ago' => monitor_file_age('logs/autonomous-cycle-report.json'),
                ],
                'live' => $live,
            ];

        case 'agents':
            return [
                'status' => 'ok',
                'generated_at' => date('c'),
                'agents' => monitor_agent_activity($tasks),
            ];

        case 'messages':
            return [
                'status' => 'ok',
                'commands' => monitor_read_jsonl('storage/private/agent-interventions.jsonl', 200),
                'responses' => monitor_read_jsonl('storage/private/agent-intervention-responses.jsonl', 200),
                'steps' => monitor_read_jsonl('logs/autonomous/agent-steps.jsonl', 300),
            ];

        case 'tasks':
            return [
                'status' => 'ok',
                'source' => $queueState['source'],
                'tasks' => array_slice($tasks, 0, 80),
            ];

        case 'logs':
            return [
                'status' => 'ok',
                'logs' => [
                    'cycle' => $cycle,
                    'live' => $live,
                    'learning' => monitor_read_jsonl('logs/autonomous/learning-history.jsonl', 120),
                    'steps' => monitor_read_jsonl('logs/autonomous/agent-steps.jsonl', 200),
                ],
            ];

        case 'send-command':
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                http_response_code(405);
                return ['status' => 'error', 'message' => 'Use POST para enviar comandos.'];
            }
            $payload = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($payload)) {
                $payload = $_POST;
            }
            return monitor_send_operational_command($payload);

        case 'generate-tasks':
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                http_response_code(405);
                return ['status' => 'error', 'message' => 'Use POST para gerar tarefas.'];
            }
            $execution = monitor_generate_tasks();
            return [
                'status' => $execution['ok'] ? 'ok' : 'error',
                'message' => $execution['ok'] ? 'Executor acionado para gerar/evoluir o backlog.' : 'Falha ao acionar executor.',
                'execution' => $execution,
            ];
    }

    http_response_code(400);
    return ['status' => 'error', 'message' => 'Acao desconhecida.'];
}

echo json_encode(monitor_response(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
