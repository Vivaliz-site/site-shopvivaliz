<?php

declare(strict_types=1);

final class ShopvivalizUpdateStatusAgent
{
    public function run(array $options = []): array
    {
        $root = dirname(__DIR__);
        $data = [
            'ok' => true,
            'agent' => 'update_status',
            'version' => '9.2.85',
            'generated_at' => date('c'),
            'checks' => [],
        ];
        $paths = [
            'installer_update' => $root . '/installer/update.php',
            'agent_handoff' => $root . '/installer/agent-handoff.php',
            'sync_runner' => $root . '/installer/sync-runner.php',
            'autonomous_report' => $root . '/api/agent/autonomous-report.php',
            'media_quality' => $root . '/api/agent/media-quality.php',
            'media_mismatch' => $root . '/api/agent/media-mismatch.php',
        ];
        foreach ($paths as $key => $path) {
            $data['checks'][$key] = [
                'exists' => is_file($path),
                'readable' => is_readable($path),
                'mtime' => is_file($path) ? date('c', (int)filemtime($path)) : null,
            ];
            if (!is_file($path)) $data['ok'] = false;
        }
        $data['git'] = [
            'head_file_exists' => is_file($root . '/.git/HEAD'),
            'head' => is_file($root . '/.git/HEAD') ? trim((string)file_get_contents($root . '/.git/HEAD')) : null,
        ];
        $this->beat($data);
        return $data;
    }

    private function beat(array $data): void
    {
        $pdo = $this->pdo();
        if (!$pdo) return;
        try { $pdo->prepare('INSERT INTO sv_agent_heartbeats (agent, status, summary_json, created_at) VALUES (?, ?, ?, NOW())')->execute(['update_status', $data['ok'] ? 'ok' : 'warning', json_encode($data)]); } catch (Throwable $ignored) {}
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
