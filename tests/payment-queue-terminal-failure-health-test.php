<?php
declare(strict_types=1);
function pqtf_assert(bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
$tmp = sys_get_temp_dir() . '/sv-queue-terminal-' . getmypid();
@mkdir($tmp, 0700, true);
$queueFile = $tmp . '/queue.json';
$heartbeat = $tmp . '/heartbeat.json';
putenv('SHOPVIVALIZ_QUEUE_DSN=invalid:force-file-backend');
putenv('SHOPVIVALIZ_QUEUE_FILE=' . $queueFile);
putenv('SHOPVIVALIZ_QUEUE_HEARTBEAT_FILE=' . $heartbeat);
require_once dirname(__DIR__) . '/core/queue/queue.php';
file_put_contents($heartbeat, json_encode(['checked_at'=>gmdate('c')]));
$old = gmdate('c', time() - 864000);
file_put_contents($queueFile, json_encode(['metadata'=>['schema_version'=>2],'tasks'=>[[
    'id'=>1,'job_type'=>'webhook:mercadopago','status'=>'failed','attempts'=>5,'last_error'=>'historical_failure','created_at'=>$old,'finished_at'=>$old,
]]]));
$health = sv_queue_health(300);
pqtf_assert(($health['ok'] ?? false) === true, 'historical terminal failure must not poison current health');
pqtf_assert((int)($health['failed'] ?? -1) === 0, 'failed must represent recent health-impacting failures');
pqtf_assert((int)($health['failed_total'] ?? 0) === 1, 'failed_total must preserve terminal failure observability');
$now = gmdate('c');
file_put_contents($queueFile, json_encode(['metadata'=>['schema_version'=>2],'tasks'=>[[
    'id'=>2,'job_type'=>'webhook:mercadopago','status'=>'failed','attempts'=>5,'last_error'=>'recent_failure','created_at'=>$now,'finished_at'=>$now,
]]]));
$recent = sv_queue_health(300);
pqtf_assert(($recent['ok'] ?? true) === false && (int)($recent['failed'] ?? 0) === 1, 'recent terminal failure must fail current health');
fwrite(STDOUT, "PASS: terminal failure health window.\n");
