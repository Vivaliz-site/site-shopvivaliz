<?php

declare(strict_types=1);

final class ShopvivalizSelfTestAgent
{
    public function run(array $options = []): array
    {
        $checks = [];
        $checks[] = ['name' => 'php_version', 'ok' => version_compare(PHP_VERSION, '8.0.0', '>='), 'value' => PHP_VERSION];
        $checks[] = ['name' => 'pdo_loaded', 'ok' => extension_loaded('pdo'), 'value' => extension_loaded('pdo')];
        $checks[] = ['name' => 'json_loaded', 'ok' => extension_loaded('json'), 'value' => extension_loaded('json')];
        $checks[] = ['name' => 'curl_loaded', 'ok' => extension_loaded('curl'), 'value' => extension_loaded('curl')];
        $checks[] = ['name' => 'storage_writable', 'ok' => $this->isWritablePath('storage'), 'value' => 'storage'];

        $ok = true;
        foreach ($checks as $check) {
            if (!$check['ok']) $ok = false;
        }

        $result = ['ok' => $ok, 'agent' => 'self_test', 'generated_at' => date('c'), 'checks' => $checks];
        $this->heartbeat($result);
        return $result;
    }

    private function isWritablePath(string $path): bool
    {
        if (!is_dir($path)) @mkdir($path, 0775, true);
        return is_dir($path) && is_writable($path);
    }

    private function heartbeat(array $result): void
    {
        $pdo = $this->pdo();
        if (!$pdo) return;
        try {
            $pdo->prepare('INSERT INTO sv_agent_heartbeats (agent, status, summary_json, created_at) VALUES (?, ?, ?, NOW())')->execute(['self_test', $result['ok'] ? 'ok' : 'warning', json_encode($result)]);
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
