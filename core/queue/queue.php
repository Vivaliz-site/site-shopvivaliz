<?php
declare(strict_types=1);

require_once __DIR__ . '/../logger/logger.php';

function sv_queue_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = getenv('SHOPVIVALIZ_QUEUE_DSN') ?: 'sqlite:' . dirname(__DIR__, 2) . '/storage/queue.sqlite';
    $pdo = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
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
    $pdo->beginTransaction();
    $rows = $pdo->query('SELECT * FROM queue_jobs WHERE status="queued" AND datetime(available_at) <= datetime("now") ORDER BY priority ASC, id ASC LIMIT ' . max(1, $limit))->fetchAll();
    $ids = [];
    foreach ($rows as $row) $ids[] = (int)$row['id'];
    if ($ids !== []) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE queue_jobs SET status='running', started_at=CURRENT_TIMESTAMP, attempts=attempts+1 WHERE id IN ($in)");
        $stmt->execute($ids);
    }
    $pdo->commit();
    return $rows;
}

function sv_queue_finish(int $id, string $status, ?string $error = null): void
{
    $pdo = sv_queue_db();
    $stmt = $pdo->prepare('UPDATE queue_jobs SET status=?, finished_at=CURRENT_TIMESTAMP, last_error=? WHERE id=?');
    $stmt->execute([$status, $error, $id]);
}
