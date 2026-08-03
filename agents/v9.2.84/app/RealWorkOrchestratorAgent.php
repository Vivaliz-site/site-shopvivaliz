<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/blog-article-repository.php';

final class ShopvivalizRealWorkOrchestratorAgent
{
    public function run(array $options = []): array
    {
        $startedAt = date('c');
        $actions = [];
        $errors = [];
        $root = dirname(__DIR__, 3);

        $this->storageRoundTrip($root, $actions, $errors);
        $this->maintainBlogQueue($options, $actions, $errors);
        $this->syncMappedImages($root, $actions, $errors);
        $this->optimizeImageJobs($root, $actions, $errors);
        $this->lintCriticalRuntime($root, $actions, $errors);

        $ok = $errors === [] && count($actions) >= 5;
        $result = [
            'ok' => $ok,
            'agent' => 'real_work_orchestrator',
            'version' => '10.0.0-evidence-based',
            'execution_status' => $ok ? 'completed' : 'failed',
            'execution_completed' => $ok,
            'started_at' => $startedAt,
            'finished_at' => date('c'),
            'work_evidence_count' => count($actions),
            'changed_items' => array_sum(array_map(static fn(array $action): int => (int)($action['changed_items'] ?? 0), $actions)),
            'actions' => $actions,
            'errors' => $errors,
        ];

        $this->persistEvidence($root, $result, $errors);
        if ($errors !== []) {
            $result['ok'] = false;
            $result['execution_status'] = 'failed';
            $result['execution_completed'] = false;
            $result['errors'] = $errors;
        }
        return $result;
    }

    private function storageRoundTrip(string $root, array &$actions, array &$errors): void
    {
        try {
            $directory = $root . '/storage/agent-evidence';
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('storage_directory_unavailable');
            }
            $path = $directory . '/round-trip-' . getmypid() . '.txt';
            $payload = bin2hex(random_bytes(24));
            if (file_put_contents($path, $payload, LOCK_EX) === false) throw new RuntimeException('storage_write_failed');
            $readback = file_get_contents($path);
            @unlink($path);
            if ($readback !== $payload) throw new RuntimeException('storage_readback_failed');
            $actions[] = ['action' => 'storage_round_trip', 'status' => 'verified', 'verified' => true, 'changed_items' => 0, 'evidence_sha256' => hash('sha256', $payload)];
        } catch (Throwable $e) {
            $errors[] = ['action' => 'storage_round_trip', 'error' => $e->getMessage()];
        }
    }

    private function maintainBlogQueue(array $options, array &$actions, array &$errors): void
    {
        try {
            $repository = BlogArticleRepository::fromApplicationDatabase();
            if (!$repository->isDatabaseAvailable()) throw new RuntimeException('blog_database_unavailable');
            $target = max(3, min(36, (int)($options['blog_queue_depth'] ?? 12)));
            $queue = $repository->ensureAutonomousQueue($target);
            $published = $repository->publishDue();
            $queueErrors = array_values((array)($queue['errors'] ?? []));
            if ($queueErrors !== []) throw new RuntimeException('blog_queue_errors:' . implode('|', $queueErrors));
            $future = (int)($queue['future'] ?? 0);
            if ($future < $target) throw new RuntimeException('blog_queue_below_target');
            $actions[] = [
                'action' => 'maintain_blog_queue',
                'status' => 'completed',
                'verified' => true,
                'queue_target' => $target,
                'scheduled_future' => $future,
                'queued_new' => (int)($queue['queued'] ?? 0),
                'published_due' => (int)$published,
                'changed_items' => (int)($queue['queued'] ?? 0) + (int)$published,
            ];
        } catch (Throwable $e) {
            $errors[] = ['action' => 'maintain_blog_queue', 'error' => $e->getMessage()];
        }
    }

    private function syncMappedImages(string $root, array &$actions, array &$errors): void
    {
        $mapping = $root . '/logs/olist-images-mapping.json';
        $script = $root . '/olist/sync-images-to-site.php';
        if (!is_file($mapping)) {
            $actions[] = ['action' => 'sync_mapped_images', 'status' => 'verified_no_input', 'verified' => true, 'changed_items' => 0, 'reason' => 'mapping_not_present'];
            return;
        }
        if (!is_file($script)) {
            $errors[] = ['action' => 'sync_mapped_images', 'error' => 'sync_script_missing'];
            return;
        }

        $execution = $this->execute(['/usr/bin/php', $script], $root, 300);
        $payload = $this->extractJsonObject($execution['stdout']);
        if ($execution['exit_code'] !== 0 || !is_array($payload) || ($payload['sucesso'] ?? false) !== true || (int)($payload['erros'] ?? 0) !== 0) {
            $errors[] = ['action' => 'sync_mapped_images', 'error' => 'image_sync_failed', 'exit_code' => $execution['exit_code'], 'output_sha256' => hash('sha256', $execution['stdout'] . $execution['stderr'])];
            return;
        }
        $actions[] = ['action' => 'sync_mapped_images', 'status' => 'completed', 'verified' => true, 'changed_items' => (int)($payload['produtos_atualizados'] ?? 0), 'output_sha256' => hash('sha256', $execution['stdout'])];
    }

    private function optimizeImageJobs(string $root, array &$actions, array &$errors): void
    {
        $report = $root . '/logs/ai-images-report.json';
        $script = $root . '/scripts/auto-optimize-images.py';
        if (!is_file($report)) {
            $actions[] = ['action' => 'optimize_image_jobs', 'status' => 'verified_no_input', 'verified' => true, 'changed_items' => 0, 'reason' => 'image_report_not_present'];
            return;
        }
        if (!is_file($script)) {
            $errors[] = ['action' => 'optimize_image_jobs', 'error' => 'optimizer_script_missing'];
            return;
        }

        $outputPath = $root . '/storage/agent-evidence/image-optimization.json';
        $execution = $this->execute(['/usr/bin/python3', $script, '--report', $report, '--out', $outputPath, '--check-urls', '--limit', '5'], $root, 900);
        if ($execution['exit_code'] !== 0 || !is_file($outputPath)) {
            $errors[] = ['action' => 'optimize_image_jobs', 'error' => 'optimizer_failed', 'exit_code' => $execution['exit_code'], 'output_sha256' => hash('sha256', $execution['stdout'] . $execution['stderr'])];
            return;
        }
        $payload = json_decode((string)file_get_contents($outputPath), true);
        if (!is_array($payload) || ($payload['ok'] ?? false) !== true || !empty($payload['dry_run'])) {
            $errors[] = ['action' => 'optimize_image_jobs', 'error' => 'optimizer_evidence_invalid'];
            return;
        }
        $actions[] = ['action' => 'optimize_image_jobs', 'status' => 'completed', 'verified' => true, 'changed_items' => (int)($payload['regenerated'] ?? 0), 'bad_products' => (int)($payload['bad_products'] ?? 0), 'evidence_sha256' => hash_file('sha256', $outputPath)];
    }

    private function lintCriticalRuntime(string $root, array &$actions, array &$errors): void
    {
        $files = [
            $root . '/api/blog/publish-scheduled.php',
            $root . '/includes/blog-article-repository.php',
            $root . '/scripts/run-blog-email-campaigns.php',
        ];
        $checked = 0;
        foreach ($files as $file) {
            if (!is_file($file)) {
                $errors[] = ['action' => 'lint_critical_runtime', 'error' => 'missing_file', 'file' => basename($file)];
                continue;
            }
            $execution = $this->execute(['/usr/bin/php', '-l', $file], $root, 30);
            if ($execution['exit_code'] !== 0) {
                $errors[] = ['action' => 'lint_critical_runtime', 'error' => 'php_lint_failed', 'file' => basename($file)];
                continue;
            }
            $checked++;
        }
        if ($checked === count($files)) {
            $actions[] = ['action' => 'lint_critical_runtime', 'status' => 'verified', 'verified' => true, 'checked_files' => $checked, 'changed_items' => 0];
        }
    }

    private function persistEvidence(string $root, array $result, array &$errors): void
    {
        try {
            $directory = $root . '/storage/agent-evidence';
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('evidence_directory_unavailable');
            $path = $directory . '/latest-real-work.json';
            $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (!is_string($json) || file_put_contents($path, $json . "\n", LOCK_EX) === false) throw new RuntimeException('evidence_write_failed');
            $readback = json_decode((string)file_get_contents($path), true);
            if (!is_array($readback) || ($readback['started_at'] ?? null) !== ($result['started_at'] ?? null)) throw new RuntimeException('evidence_readback_failed');
        } catch (Throwable $e) {
            $errors[] = ['action' => 'persist_evidence', 'error' => $e->getMessage()];
        }
    }

    private function execute(array $command, string $cwd, int $timeoutSeconds): array
    {
        $escaped = array_map('escapeshellarg', $command);
        $shell = 'cd ' . escapeshellarg($cwd) . ' && timeout ' . max(1, $timeoutSeconds) . 's ' . implode(' ', $escaped);
        $stdoutPath = tempnam(sys_get_temp_dir(), 'sv-out-');
        $stderrPath = tempnam(sys_get_temp_dir(), 'sv-err-');
        $exitCode = 1;
        exec($shell . ' >' . escapeshellarg($stdoutPath) . ' 2>' . escapeshellarg($stderrPath), $unused, $exitCode);
        $stdout = (string)@file_get_contents($stdoutPath);
        $stderr = (string)@file_get_contents($stderrPath);
        @unlink($stdoutPath);
        @unlink($stderrPath);
        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function extractJsonObject(string $output): ?array
    {
        $start = strpos($output, '{');
        $end = strrpos($output, '}');
        if ($start === false || $end === false || $end < $start) return null;
        $payload = json_decode(substr($output, $start, $end - $start + 1), true);
        return is_array($payload) ? $payload : null;
    }
}
