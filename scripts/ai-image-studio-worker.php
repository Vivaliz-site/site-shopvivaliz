<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/ai-image-studio/process_item.php';
require_once __DIR__ . '/../core/queue/queue.php';

$once = in_array('--once', $argv, true);
$limit = 3;
$idleSleep = 5;
foreach ($argv as $arg) {
    if (str_starts_with((string)$arg, '--limit=')) {
        $limit = max(1, (int)substr((string)$arg, 8));
    }
    if (str_starts_with((string)$arg, '--sleep=')) {
        $idleSleep = max(1, (int)substr((string)$arg, 8));
    }
}

fwrite(STDOUT, "[ai-image-worker] Starting with limit={$limit}, idle_sleep={$idleSleep}s\n");
sv_log('ai_image_worker_started', 'queue', ['limit' => $limit, 'idle_sleep' => $idleSleep, 'once' => $once]);

while (true) {
    $jobs = sv_queue_claim($limit);
    if ($jobs === []) {
        fwrite(STDOUT, "[ai-image-worker] No queued jobs, sleeping...\n");
        sv_log('ai_image_worker_idle', 'queue', ['limit' => $limit, 'idle_sleep' => $idleSleep, 'once' => $once]);
        if ($once) {
            exit(0);
        }
        sleep($idleSleep);
        continue;
    }

    foreach ($jobs as $job) {
        $jobId = (int)($job['id'] ?? 0);
        $payload = json_decode((string)($job['payload'] ?? '{}'), true);
        if (!is_array($payload)) {
            sv_queue_finish($jobId, 'failed', 'payload invalid');
            continue;
        }

        try {
            $result = ai_studio_process_queued_job($payload);
            sv_queue_finish($jobId, (($result['success'] ?? false) ? 'done' : 'failed'), null);
            fwrite(STDOUT, "[ai-image-worker] Job {$jobId} finished with status=" . (($result['success'] ?? false) ? 'done' : 'failed') . "\n");
            sv_log('ai_image_worker_job_finished', 'queue', [
                'job_id' => $jobId,
                'status' => (($result['success'] ?? false) ? 'done' : 'failed'),
                'product_id' => (int)($payload['product_id'] ?? 0),
                'provider' => (string)($payload['provider'] ?? ''),
                'target_channel' => (string)($payload['target_channel'] ?? ''),
            ]);
        } catch (Throwable $e) {
            sv_queue_finish($jobId, 'failed', $e->getMessage());
            fwrite(STDERR, "[ai-image-worker] Job {$jobId} failed: {$e->getMessage()}\n");
            sv_log('ai_image_worker_job_failed', 'queue', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
                'product_id' => (int)($payload['product_id'] ?? 0),
                'provider' => (string)($payload['provider'] ?? ''),
                'target_channel' => (string)($payload['target_channel'] ?? ''),
            ]);
        }
    }

    if ($once) {
        exit(0);
    }
}
