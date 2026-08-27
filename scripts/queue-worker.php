<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/queue/queue.php';
require_once __DIR__ . '/../includes/webhook-job-dispatcher.php';

function queue_worker_job_label(array $job): string
{
    return sprintf(
        '#%d %s',
        (int)($job['id'] ?? 0),
        (string)($job['job_type'] ?? 'unknown')
    );
}

function queue_worker_handle_job(array $job): void
{
    $jobType = (string)($job['job_type'] ?? '');
    $payload = json_decode((string)($job['payload'] ?? '{}'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('invalid_payload');
    }

    if (str_starts_with($jobType, 'ai_image_studio.')) {
        require_once __DIR__ . '/../admin/ai-image-studio/process_item.php';
        $result = ai_studio_process_queued_job($payload);
        if (!($result['success'] ?? false)) {
            throw new RuntimeException((string)($result['error'] ?? 'ai_job_failed'));
        }
        return;
    }

    if (str_starts_with($jobType, 'webhook:')) {
        $result = sv_webhook_job_dispatch($job);
        if (!($result['success'] ?? false)) {
            throw new RuntimeException((string)($result['error'] ?? 'webhook_job_failed'));
        }
        return;
    }

    if ($jobType === 'maintenance.reap_stale') {
        sv_queue_reap_stale(900);
        return;
    }

    // Fallback seguro: job desconhecido nao executa acao externa.
}

function queue_worker_log(string $message): void
{
    fwrite(STDOUT, '[queue-worker] ' . $message . PHP_EOL);
}

$once = in_array('--once', $argv, true);
$limit = 5;
$idleSleep = 5;
foreach ($argv as $arg) {
    if (str_starts_with((string)$arg, '--limit=')) {
        $limit = max(1, (int)substr((string)$arg, 8));
    }
    if (str_starts_with((string)$arg, '--sleep=')) {
        $idleSleep = max(1, (int)substr((string)$arg, 8));
    }
}

// systemctl restart (deploy-production.sh) enviava SIGTERM direto, matando o
// worker no meio do processamento de um job -- o job ficava travado em
// 'running' ate o reaper de jobs antigos (ate 15 min depois). Com o handler
// abaixo, o worker termina o job em curso e sai limpo assim que possivel.
$shuttingDown = false;
if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $shutdownHandler = static function () use (&$shuttingDown): void {
        $shuttingDown = true;
    };
    pcntl_signal(SIGTERM, $shutdownHandler);
    pcntl_signal(SIGINT, $shutdownHandler);
}

queue_worker_log("starting limit={$limit} idle_sleep={$idleSleep}s once=" . ($once ? 'yes' : 'no'));

while (true) {
    if ($shuttingDown) {
        queue_worker_log('graceful shutdown after signal (idle)');
        exit(0);
    }
    $jobs = sv_queue_claim($limit);
    if ($jobs === []) {
        sv_queue_reap_stale(900);
        usleep(500000);
        if ($once) {
            queue_worker_log('idle; exiting once mode');
            exit(0);
        }
        sleep($idleSleep);
        continue;
    }
    foreach ($jobs as $job) {
        try {
            $label = queue_worker_job_label($job);
            queue_worker_log("processing {$label}");
            sv_log('job_start', 'queue', ['id' => $job['id'], 'type' => $job['job_type']]);
            queue_worker_handle_job($job);
            sv_queue_finish((int)$job['id'], 'done');
            queue_worker_log("done {$label}");
        } catch (Throwable $e) {
            sv_queue_retry_or_fail($job, $e->getMessage());
            queue_worker_log('error #' . (int)$job['id'] . ' attempts=' . (int)($job['attempts'] ?? 1) . ' ' . $e->getMessage());
        }
    }

    if ($once) {
        queue_worker_log('completed once mode');
        exit(0);
    }

    if ($shuttingDown) {
        queue_worker_log('graceful shutdown after signal');
        exit(0);
    }
}
