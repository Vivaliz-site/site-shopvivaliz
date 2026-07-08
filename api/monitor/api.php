<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function monitor_root(): string {
    return dirname(__DIR__, 2);
}

function monitor_read_json(string $relPath, array $fallback = []): array {
    $path = monitor_root() . '/' . ltrim($relPath, '/');
    if (!is_file($path)) {
        return $fallback;
    }

    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : $fallback;
}

function monitor_file_age(string $relPath): ?int {
    $path = monitor_root() . '/' . ltrim($relPath, '/');
    return is_file($path) ? (int)(time() - filemtime($path)) : null;
}

function monitor_read_first_json(array $relPaths, array $fallback = []): array {
    foreach ($relPaths as $relPath) {
        $data = monitor_read_json($relPath, []);
        if ($data !== []) {
            return $data;
        }
    }

    return $fallback;
}

function monitor_queue_candidates(): array {
    return [
        'tasks-queue.json',
        'logs/tasks-queue.json',
    ];
}

function monitor_read_queue(): array {
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
    }

    return ['tasks' => [], 'source' => null];
}

function monitor_count_tasks(array $tasks, array $statuses): int {
    return count(array_filter($tasks, static function ($task) use ($statuses): bool {
        $status = strtolower((string)($task['status'] ?? ''));
        return in_array($status, $statuses, true);
    }));
}

function getStatus(): array {
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
                'message' => 'Monitor operacional com sincronização triambiente',
                'autonomous_status' => [
                    'is_running' => $isRunning,
                    'last_cycle_seconds_ago' => monitor_file_age('logs/tri-environment-sync.json'),
                    'status' => $triStatus === 'healthy' ? 'healthy' : ($triStatus === 'warning' ? 'warning' : 'critical')
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
        case 'tasks':
            return [
                'status' => 'ok',
                'message' => 'Tasks API conectada à fila canônica',
                'source' => $queueSource,
                'tasks' => array_slice($tasks, 0, 25),
                'queue_file_age_s' => $queueSource ? monitor_file_age($queueSource) : null,
            ];
        case 'logs':
            return [
                'status' => 'ok',
                'message' => 'Logs API conectada ao rastro autônomo',
                'logs' => [
                    'tri_environment_sync' => $triSync,
                    'autonomous_cycle_report' => monitor_read_json('logs/autonomous-cycle-report.json', []),
                    'queue_source' => $queueSource,
                ],
            ];
        default:
            http_response_code(400);
            return ['status' => 'error', 'message' => 'Ação desconhecida.'];
    }
}

// Para compatibilidade com system-health-check.py que procura 'getStatus'
$response = getStatus();
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
