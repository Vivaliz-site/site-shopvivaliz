<?php

declare(strict_types=1);

require_once __DIR__ . '/SafeMigrationRepairAgent.php';
require_once __DIR__ . '/OlistImageRepairAgent.php';
require_once __DIR__ . '/SelfTestAgent.php';
require_once __DIR__ . '/LoopStarterAgent.php';
require_once __DIR__ . '/AutonomousReportAgent.php';

final class ShopvivalizAutonomousWatchdogAgent
{
    public function run(array $options = []): array
    {
        $startedAt = date('c');
        $steps = [];
        $errors = [];
        $lock = $this->lock();
        if (!$lock['ok']) {
            return ['ok' => false, 'agent' => 'autonomous_watchdog', 'status' => 'locked', 'message' => $lock['message'], 'generated_at' => date('c')];
        }

        try {
            $steps['safe_migration_repair'] = (new ShopvivalizSafeMigrationRepairAgent())->run($options);
            $steps['olist_image_repair'] = (new ShopvivalizOlistImageRepairAgent())->run($options);
            $steps['self_test'] = (new ShopvivalizSelfTestAgent())->run($options);

            if (!empty($options['run_loop'])) {
                $steps['loop_starter'] = (new ShopvivalizLoopStarterAgent())->run($options);
            }

            $steps['autonomous_report'] = (new ShopvivalizAutonomousReportAgent())->run($options);

            foreach ($steps as $step) {
                if (is_array($step) && array_key_exists('ok', $step) && !$step['ok']) {
                    $errors[] = $step['agent'] ?? 'unknown_agent';
                }
            }

            $result = [
                'ok' => count($errors) === 0,
                'agent' => 'autonomous_watchdog',
                'version' => '9.2.84-resident-autonomous-watchdog',
                'started_at' => $startedAt,
                'finished_at' => date('c'),
                'errors' => $errors,
                'steps' => $steps,
            ];
            $this->heartbeat($result);
            return $result;
        } finally {
            $this->unlock($lock['path'] ?? null);
        }
    }

    private function lock(): array
    {
        $dir = 'storage/locks';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $path = $dir . '/shopvivaliz-autonomous-watchdog.lock';
        if (is_file($path) && (time() - (int)filemtime($path)) < 900) {
            return ['ok' => false, 'path' => $path, 'message' => 'Watchdog ja esta em execucao.'];
        }
        @file_put_contents($path, (string)getmypid());
        return ['ok' => true, 'path' => $path, 'message' => 'Lock criado.'];
    }

    private function unlock(?string $path): void
    {
        if ($path && is_file($path)) @unlink($path);
    }

    private function heartbeat(array $result): void
    {
        $pdo = $this->pdo();
        if (!$pdo) return;
        try {
            $stmt = $pdo->prepare('INSERT INTO sv_agent_heartbeats (agent, status, summary_json, created_at) VALUES (?, ?, ?, NOW())');
            $stmt->execute(['autonomous_watchdog', $result['ok'] ? 'ok' : 'warning', json_encode($result)]);
        } catch (Throwable $ignored) {}
    }

    private function pdo(): ?PDO
    {
        foreach (['sv_pdo', 'sv_db', 'db', 'get_pdo'] as $fn) {
            if (function_exists($fn)) {
                $value = $fn();
                if ($value instanceof PDO) return $value;
            }
        }
        return null;
    }
}
