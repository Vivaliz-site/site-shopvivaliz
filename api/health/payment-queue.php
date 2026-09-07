<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/core/queue/queue.php';

$health = sv_queue_health(300);
http_response_code(($health['ok'] ?? false) ? 200 : 503);
echo json_encode([
    'ok' => (bool)($health['ok'] ?? false),
    'queued' => (int)($health['queued'] ?? 0),
    'running' => (int)($health['running'] ?? 0),
    'failed' => (int)($health['failed'] ?? 0),
    'stale' => (int)($health['stale'] ?? 0),
    'oldest_queued_age_seconds' => (int)($health['oldest_queued_age_seconds'] ?? 0),
    'worker_ok' => (bool)($health['worker_ok'] ?? false),
    'worker_age_seconds' => $health['worker_age_seconds'] ?? null,
], JSON_UNESCAPED_SLASHES);
