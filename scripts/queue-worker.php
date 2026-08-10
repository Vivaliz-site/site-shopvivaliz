<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/queue/queue.php';

while (true) {
    $jobs = sv_queue_claim(5);
    if ($jobs === []) {
        usleep(500000);
        continue;
    }
    foreach ($jobs as $job) {
        try {
            sv_log('job_start', 'queue', ['id' => $job['id'], 'type' => $job['job_type']]);
            sv_queue_finish((int)$job['id'], 'completed');
        } catch (Throwable $e) {
            sv_queue_finish((int)$job['id'], 'failed', $e->getMessage());
        }
    }
}
