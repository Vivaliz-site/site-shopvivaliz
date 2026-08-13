<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/admin-guard.php';
require_once __DIR__ . '/../../../core/queue/queue.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Use GET.']);
    exit;
}

$ids = array_values(array_unique(array_filter(array_map(
    static fn(string $value): int => (int)trim($value),
    explode(',', (string)($_GET['ids'] ?? ''))
), static fn(int $value): bool => $value > 0)));
$ids = array_slice($ids, 0, 100);

if ($ids === []) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Informe ao menos um job id valido.']);
    exit;
}

/** @return array{id:int,status:string,attempts:int,last_error:string,started_at:?string,finished_at:?string} */
function ais_generation_status_row(array $row): array
{
    $error = trim((string)($row['last_error'] ?? ''));
    if ($error !== '') {
        $error = preg_replace('/(?:sk-(?:proj-)?|AIza|ant-api)[A-Za-z0-9_\-]{8,}/', '[redacted]', $error) ?? $error;
        $error = mb_substr($error, 0, 360, 'UTF-8');
    }
    return [
        'id' => (int)($row['id'] ?? 0),
        'status' => strtolower(trim((string)($row['status'] ?? 'queued'))),
        'attempts' => (int)($row['attempts'] ?? 0),
        'last_error' => $error,
        'started_at' => isset($row['started_at']) && $row['started_at'] !== '' ? (string)$row['started_at'] : null,
        'finished_at' => isset($row['finished_at']) && $row['finished_at'] !== '' ? (string)$row['finished_at'] : null,
    ];
}

try {
    $jobs = [];
    if (sv_queue_uses_file_backend()) {
        $queue = sv_queue_file_bootstrap();
        $wanted = array_fill_keys($ids, true);
        foreach ((array)($queue['tasks'] ?? []) as $task) {
            $id = (int)($task['id'] ?? 0);
            if ($id > 0 && isset($wanted[$id])) {
                $jobs[$id] = ais_generation_status_row($task);
            }
        }
    } else {
        $pdo = sv_queue_db();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, status, attempts, last_error, started_at, finished_at FROM queue_jobs WHERE id IN ({$placeholders})"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $jobs[$id] = ais_generation_status_row($row);
            }
        }
    }

    $result = [];
    foreach ($ids as $id) {
        $result[] = $jobs[$id] ?? [
            'id' => $id,
            'status' => 'unknown',
            'attempts' => 0,
            'last_error' => 'Job nao encontrado na fila atual.',
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    echo json_encode([
        'success' => true,
        'jobs' => $result,
        'count' => count($result),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[ai-image-studio] generation_status: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nao foi possivel consultar o andamento da fila.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
