<?php
declare(strict_types=1);

function pqh_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$tmp = sys_get_temp_dir() . '/sv-queue-health-' . getmypid();
@mkdir($tmp, 0700, true);
$queueFile = $tmp . '/queue.json';
$heartbeatFile = $tmp . '/worker-heartbeat.json';
putenv('SHOPVIVALIZ_QUEUE_FILE=' . $queueFile);
putenv('SHOPVIVALIZ_QUEUE_HEARTBEAT_FILE=' . $heartbeatFile);

require_once dirname(__DIR__) . '/core/queue/queue.php';
pqh_assert(function_exists('sv_queue_health'), 'sv_queue_health() must exist');

$old = gmdate('c', time() - 900);
file_put_contents($queueFile, json_encode(['metadata'=>['schema_version'=>2], 'tasks'=>[[
    'id'=>1, 'job_type'=>'webhook:mercadopago', 'status'=>'queued', 'created_at'=>$old, 'available_at'=>$old, 'attempts'=>0,
]]], JSON_PRETTY_PRINT));
file_put_contents($heartbeatFile, json_encode(['checked_at'=>gmdate('c')]));

$health = sv_queue_health(300);
pqh_assert(($health['ok'] ?? true) === false, 'stale queued payment job must fail health');
pqh_assert((int)($health['stale'] ?? 0) === 1, 'stale queued job must be counted');
pqh_assert((int)($health['oldest_queued_age_seconds'] ?? 0) >= 800, 'oldest queued age must be exposed');
pqh_assert(($health['worker_ok'] ?? false) === true, 'fresh worker heartbeat must be healthy');

file_put_contents($queueFile, json_encode(['metadata'=>['schema_version'=>2], 'tasks'=>[]], JSON_PRETTY_PRINT));
file_put_contents($heartbeatFile, json_encode(['checked_at'=>gmdate('c', time() - 900)]));
$workerDown = sv_queue_health(300);
pqh_assert(($workerDown['ok'] ?? true) === false, 'stale worker heartbeat must fail health even with empty queue');
pqh_assert(($workerDown['worker_ok'] ?? true) === false, 'worker_ok must be false for stale heartbeat');

$worker = (string)file_get_contents(dirname(__DIR__) . '/scripts/queue-worker.php');
pqh_assert(str_contains($worker, 'sv_queue_touch_worker_heartbeat'), 'queue worker must update heartbeat');
$endpoint = dirname(__DIR__) . '/api/health/payment-queue.php';
pqh_assert(is_file($endpoint), 'public payment queue health endpoint must exist');

@unlink($queueFile);
@unlink($heartbeatFile);
@rmdir($tmp);
putenv('SHOPVIVALIZ_QUEUE_FILE');
putenv('SHOPVIVALIZ_QUEUE_HEARTBEAT_FILE');
fwrite(STDOUT, "PASS: payment queue health contract.\n");
