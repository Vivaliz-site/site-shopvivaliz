<?php
declare(strict_types=1);

require_once __DIR__ . '/../logger/logger.php';

const SV_QUEUE_FILE = __DIR__ . '/../../storage/queue.json';

function sv_queue_file_path(): string
{
    $override = getenv('SHOPVIVALIZ_QUEUE_FILE');
    if (is_string($override) && trim($override) !== '') {
        return trim($override);
    }
    return SV_QUEUE_FILE;
}

function sv_queue_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = getenv('SHOPVIVALIZ_QUEUE_DSN') ?: 'sqlite:' . dirname(__DIR__, 2) . '/storage/queue.sqlite';
    $pdo = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS queue_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_type TEXT NOT NULL,
        payload TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT "queued",
        priority INTEGER NOT NULL DEFAULT 100,
        available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        started_at TEXT NULL,
        finished_at TEXT NULL,
        attempts INTEGER NOT NULL DEFAULT 0,
        last_error TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_queue_jobs_status_priority ON queue_jobs(status, priority, available_at, id)');
    return $pdo;
}

function sv_queue_uses_file_backend(): bool
{
    try {
        sv_queue_db();
        return false;
    } catch (Throwable $e) {
        return true;
    }
}

function sv_queue_file_bootstrap(): array
{
    $path = sv_queue_file_path();
    if (!is_file($path)) {
        @mkdir(dirname($path), 0775, true);
        file_put_contents(
            $path,
            json_encode(['metadata' => ['schema_version' => 2], 'tasks' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        $data = ['metadata' => ['schema_version' => 2], 'tasks' => []];
    }
    $data['metadata'] = is_array($data['metadata'] ?? null) ? $data['metadata'] : ['schema_version' => 2];
    $data['metadata']['schema_version'] = 2;
    $data['tasks'] = is_array($data['tasks'] ?? null) ? array_values($data['tasks']) : [];
    return $data;
}

function sv_queue_file_write(array $data): void
{
    $path = sv_queue_file_path();
    @mkdir(dirname($path), 0775, true);
    $lockPath = $path . '.lock';
    $lockHandle = fopen($lockPath, 'c+');
    if ($lockHandle === false) {
        throw new RuntimeException('Failed to open queue lock file.');
    }
    try {
        if (!flock($lockHandle, LOCK_EX)) {
            throw new RuntimeException('Failed to acquire queue file lock.');
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        flock($lockHandle, LOCK_UN);
    } finally {
        fclose($lockHandle);
    }
}

function sv_queue_file_now(): string
{
    return gmdate('c');
}

function sv_queue_file_enqueue(string $jobType, array $payload, int $priority = 100): int
{
    $data = sv_queue_file_bootstrap();
    $nextId = 1;
    foreach ($data['tasks'] as $task) {
        $nextId = max($nextId, (int)($task['id'] ?? 0) + 1);
    }
    $data['tasks'][] = [
        'id' => $nextId,
        'job_type' => $jobType,
        'payload' => $payload,
        'status' => 'queued',
        'priority' => $priority,
        'available_at' => sv_queue_file_now(),
        'started_at' => null,
        'finished_at' => null,
        'attempts' => 0,
        'last_error' => null,
        'created_at' => sv_queue_file_now(),
    ];
    sv_queue_file_write($data);
    return $nextId;
}

function sv_queue_file_claim(int $limit = 5): array
{
    $data = sv_queue_file_bootstrap();
    $now = time();
    $claimed = [];
    foreach ($data['tasks'] as &$task) {
        if (($task['status'] ?? '') !== 'queued') {
            continue;
        }
        $availableAt = strtotime((string)($task['available_at'] ?? 'now')) ?: 0;
        if ($availableAt > $now) {
            continue;
        }
        $task['status'] = 'running';
        $task['started_at'] = sv_queue_file_now();
        $task['attempts'] = (int)($task['attempts'] ?? 0) + 1;
        $claimed[] = [
            'id' => (int)$task['id'],
            'job_type' => (string)$task['job_type'],
            'payload' => json_encode($task['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'running',
            'priority' => (int)($task['priority'] ?? 100),
            'available_at' => (string)($task['available_at'] ?? sv_queue_file_now()),
            'started_at' => $task['started_at'],
            'finished_at' => null,
            'attempts' => (int)$task['attempts'],
            'last_error' => null,
            'created_at' => (string)($task['created_at'] ?? sv_queue_file_now()),
        ];
        if (count($claimed) >= $limit) {
            break;
        }
    }
    sv_queue_file_write($data);
    return $claimed;
}

function sv_queue_file_finish(int $id, string $status, ?string $error = null): void
{
    $data = sv_queue_file_bootstrap();
    foreach ($data['tasks'] as &$task) {
        if ((int)($task['id'] ?? 0) === $id) {
            $task['status'] = $status;
            $task['finished_at'] = sv_queue_file_now();
            $task['last_error'] = $error;
            break;
        }
    }
    sv_queue_file_write($data);
}

function sv_queue_file_summary(): array
{
    $data = sv_queue_file_bootstrap();
    $summary = ['queued' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'total' => 0, 'stale' => 0];
    $now = time();
    foreach ($data['tasks'] as $task) {
        $status = (string)($task['status'] ?? 'queued');
        if (!array_key_exists($status, $summary)) {
            $summary[$status] = 0;
        }
        $summary[$status]++;
        $summary['total']++;
        if ($status === 'running') {
            $startedAt = strtotime((string)($task['started_at'] ?? '')) ?: 0;
            if ($startedAt > 0 && ($now - $startedAt) > 900) {
                $summary['stale']++;
            }
        }
    }
    return $summary;
}

function sv_queue_reap_stale(int $maxAgeSeconds = 900): int
{
    $count = 0;
    if (sv_queue_uses_file_backend()) {
        $data = sv_queue_file_bootstrap();
        $now = time();
        foreach ($data['tasks'] as &$task) {
            if (($task['status'] ?? '') !== 'running') {
                continue;
            }
            $startedAt = strtotime((string)($task['started_at'] ?? '')) ?: 0;
            if ($startedAt <= 0 || ($now - $startedAt) <= $maxAgeSeconds) {
                continue;
            }
            $task['status'] = 'failed';
            $task['finished_at'] = sv_queue_file_now();
            $task['last_error'] = 'stale_job_reaped';
            $count++;
        }
        if ($count > 0) {
            sv_queue_file_write($data);
        }
        return $count;
    }

    $pdo = sv_queue_db();
    $stmt = $pdo->prepare('UPDATE queue_jobs SET status="failed", finished_at=CURRENT_TIMESTAMP, last_error="stale_job_reaped" WHERE status="running" AND started_at IS NOT NULL AND datetime(started_at) < datetime("now", ?)');
    $stmt->execute(['-' . max(1, $maxAgeSeconds) . ' seconds']);
    return $stmt->rowCount();
}

if (sv_queue_uses_file_backend()) {
    function sv_queue_enqueue(string $jobType, array $payload, int $priority = 100): int
    {
        return sv_queue_file_enqueue($jobType, $payload, $priority);
    }

    function sv_queue_claim(int $limit = 5): array
    {
        return sv_queue_file_claim($limit);
    }

    function sv_queue_finish(int $id, string $status, ?string $error = null): void
    {
        sv_queue_file_finish($id, $status, $error);
    }

    function sv_queue_summary(): array
    {
        return sv_queue_file_summary();
    }
} else {
    function sv_queue_enqueue(string $jobType, array $payload, int $priority = 100): int
    {
        $pdo = sv_queue_db();
        $stmt = $pdo->prepare('INSERT INTO queue_jobs(job_type, payload, status, priority) VALUES(?, ?, "queued", ?)');
        $stmt->execute([$jobType, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $priority]);
        return (int)$pdo->lastInsertId();
    }

    function sv_queue_claim(int $limit = 5): array
    {
        $pdo = sv_queue_db();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $rows = $pdo->query('SELECT * FROM queue_jobs WHERE status="queued" AND datetime(available_at) <= datetime("now") ORDER BY priority ASC, id ASC LIMIT ' . max(1, $limit))->fetchAll();
            $ids = [];
            foreach ($rows as $row) {
                $ids[] = (int)$row['id'];
            }
            if ($ids !== []) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("UPDATE queue_jobs SET status='running', started_at=CURRENT_TIMESTAMP, attempts=attempts+1 WHERE id IN ($in)");
                $stmt->execute($ids);
            }
            $pdo->exec('COMMIT');
            return $rows;
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    function sv_queue_finish(int $id, string $status, ?string $error = null): void
    {
        $pdo = sv_queue_db();
        $stmt = $pdo->prepare('UPDATE queue_jobs SET status=?, finished_at=CURRENT_TIMESTAMP, last_error=? WHERE id=?');
        $stmt->execute([$status, $error, $id]);
    }

    function sv_queue_summary(): array
    {
        $pdo = sv_queue_db();
        $rows = $pdo->query('SELECT status, COUNT(*) AS total FROM queue_jobs GROUP BY status')->fetchAll();
        $summary = ['queued' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'total' => 0];
        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '');
            if (!array_key_exists($status, $summary)) {
                $summary[$status] = 0;
            }
            $summary[$status] = (int)($row['total'] ?? 0);
        }
        $summary['total'] = array_sum($summary);
        return $summary;
    }
}
