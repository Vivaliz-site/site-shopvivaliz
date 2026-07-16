<?php

declare(strict_types=1);

final class ShopvivalizGitHubPullUpdateAgent
{
    private string $repo = 'fredmourao-ai/site-shopvivaliz';
    private string $branch = 'codex/v9-2-84-autonomous-report-agents';

    public function run(array $options = []): array
    {
        $startedAt = date('c');
        $result = [
            'ok' => false,
            'agent' => 'github_pull_update',
            'version' => '9.2.84-resident-autonomous-watchdog',
            'started_at' => $startedAt,
            'finished_at' => null,
            'mode' => $options['mode'] ?? 'prepare',
            'steps' => [],
            'errors' => [],
        ];

        try {
            $root = $this->root();
            $packageDir = $root . '/storage/github-pull/v9.2.84';
            $this->ensureDir($packageDir);
            $result['steps'][] = ['step' => 'prepare_dir', 'ok' => true, 'path' => 'storage/github-pull/v9.2.84'];

            $files = $this->packageFiles();
            $downloaded = 0;
            foreach ($files as $remote => $local) {
                $content = $this->fetchRepoFile($remote);
                if ($content === null) {
                    $result['errors'][] = ['step' => 'fetch_file', 'file' => $remote, 'message' => 'Arquivo nao encontrado ou nao acessivel.'];
                    continue;
                }
                $target = $packageDir . '/' . $local;
                $this->ensureDir(dirname($target));
                file_put_contents($target, $content);
                $downloaded++;
            }
            $result['steps'][] = ['step' => 'download_package_files', 'ok' => $downloaded === count($files), 'downloaded' => $downloaded, 'expected' => count($files)];

            if (($options['apply'] ?? '') === '1') {
                foreach ($files as $remote => $local) {
                    $source = $packageDir . '/' . $local;
                    $target = $root . '/' . $local;
                    if (!is_file($source)) continue;
                    $this->ensureDir(dirname($target));
                    copy($source, $target);
                }
                $result['steps'][] = ['step' => 'apply_files', 'ok' => true];
            } else {
                $result['steps'][] = ['step' => 'apply_files', 'ok' => true, 'status' => 'dry_run'];
            }

            if (($options['handoff'] ?? '') === '1' && is_file($root . '/installer/agent-handoff.php')) {
                ob_start();
                include $root . '/installer/agent-handoff.php';
                $handoffOutput = trim((string)ob_get_clean());
                $result['steps'][] = ['step' => 'handoff', 'ok' => true, 'output_preview' => substr($handoffOutput, 0, 500)];
            }

            $result['ok'] = count($result['errors']) === 0;
        } catch (Throwable $e) {
            $result['errors'][] = ['step' => 'run', 'message' => $e->getMessage()];
        }

        $result['finished_at'] = date('c');
        $this->heartbeat($result);
        return $result;
    }

    private function packageFiles(): array
    {
        $base = 'agents/v9.2.84/';
        return [
            $base . 'app/SafeMigrationRepairAgent.php' => 'app/SafeMigrationRepairAgent.php',
            $base . 'app/OlistImageRepairAgent.php' => 'app/OlistImageRepairAgent.php',
            $base . 'app/AutonomousReportAgent.php' => 'app/AutonomousReportAgent.php',
            $base . 'app/SelfTestAgent.php' => 'app/SelfTestAgent.php',
            $base . 'app/LoopStarterAgent.php' => 'app/LoopStarterAgent.php',
            $base . 'app/AutonomousWatchdogAgent.php' => 'app/AutonomousWatchdogAgent.php',
            $base . 'app/GitHubPullUpdateAgent.php' => 'app/GitHubPullUpdateAgent.php',
            $base . 'app/MediaQualityAgent.php' => 'app/MediaQualityAgent.php',
            $base . 'api/agent/autonomous-report.php' => 'api/agent/autonomous-report.php',
            $base . 'api/agent/autonomous-watchdog.php' => 'api/agent/autonomous-watchdog.php',
            $base . 'api/agent/media-quality.php' => 'api/agent/media-quality.php',
            $base . 'installer/agent-handoff.php' => 'installer/agent-handoff.php',
            $base . 'database/migrations/20260625_9284_autonomous_report_agents.sql' => 'database/migrations/20260625_9284_autonomous_report_agents.sql',
        ];
    }

    private function fetchRepoFile(string $path): ?string
    {
        $url = 'https://api.github.com/repos/' . $this->repo . '/contents/' . str_replace('%2F', '/', rawurlencode($path)) . '?ref=' . rawurlencode($this->branch);
        $headers = [
            'User-Agent: ShopVivaliz-Agent',
            'Accept: application/vnd.github+json',
        ];
        $key = getenv('SHOPVIVALIZ_GH_KEY') ?: getenv('SHOPVIVALIZ_REPO_KEY') ?: '';
        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        $context = stream_context_create(['http' => ['timeout' => 40, 'header' => implode("\r\n", $headers)]]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false || $raw === '') return null;
        $json = json_decode($raw, true);
        if (!is_array($json) || ($json['encoding'] ?? '') !== 'base64' || empty($json['content'])) return null;
        $content = base64_decode((string)$json['content'], true);
        return $content === false ? null : $content;
    }

    private function root(): string
    {
        return dirname(__DIR__);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
    }

    private function heartbeat(array $result): void
    {
        $pdo = $this->pdo();
        if (!$pdo) return;
        try {
            $stmt = $pdo->prepare('INSERT INTO sv_agent_heartbeats (agent, status, summary_json, created_at) VALUES (?, ?, ?, NOW())');
            $stmt->execute(['github_pull_update', $result['ok'] ? 'ok' : 'warning', json_encode($result)]);
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
