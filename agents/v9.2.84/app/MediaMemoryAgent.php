<?php

declare(strict_types=1);

final class ShopvivalizMediaMemoryAgent
{
    public function reject(array $input = []): array
    {
        $pdo = $this->pdo();
        $out = ['ok' => false, 'agent' => 'media_memory', 'action' => 'reject', 'created_at' => date('c'), 'errors' => []];
        if (!$pdo) {
            $out['errors'][] = 'database unavailable';
            return $out;
        }
        $mediaId = (int)($input['media_id'] ?? 0);
        $productId = (int)($input['product_id'] ?? 0);
        $sku = trim((string)($input['sku'] ?? ''));
        $hash = trim((string)($input['image_hash'] ?? ''));
        $reason = trim((string)($input['reason'] ?? 'manual_reject'));
        if ($mediaId <= 0 && $hash === '') {
            $out['errors'][] = 'media_id or image_hash required';
            return $out;
        }
        try {
            $this->ensureTable($pdo);
            $stmt = $pdo->prepare("INSERT INTO sv_media_reject_memory (product_id, media_id, sku, image_hash, reason, source, created_at) VALUES (?, ?, ?, ?, ?, 'manual', NOW()) ON DUPLICATE KEY UPDATE reason = VALUES(reason), source = VALUES(source)");
            $stmt->execute([$productId ?: null, $mediaId ?: null, $sku ?: null, $hash ?: null, $reason]);
            $out['ok'] = true;
            $out['media_id'] = $mediaId;
            $out['sku'] = $sku;
            $this->beat($pdo, $out);
        } catch (Throwable $e) {
            $out['errors'][] = $e->getMessage();
        }
        return $out;
    }

    public function stats(): array
    {
        $pdo = $this->pdo();
        $out = ['ok' => false, 'agent' => 'media_memory', 'action' => 'stats', 'created_at' => date('c'), 'summary' => [], 'errors' => []];
        if (!$pdo) {
            $out['errors'][] = 'database unavailable';
            return $out;
        }
        try {
            $this->ensureTable($pdo);
            $out['summary']['total'] = (int)$pdo->query('SELECT COUNT(*) FROM sv_media_reject_memory')->fetchColumn();
            $out['ok'] = true;
        } catch (Throwable $e) {
            $out['errors'][] = $e->getMessage();
        }
        return $out;
    }

    private function ensureTable(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sv_media_reject_memory (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, product_id BIGINT UNSIGNED NULL, media_id BIGINT UNSIGNED NULL, sku VARCHAR(191) NULL, image_hash VARCHAR(191) NULL, reason VARCHAR(191) NULL, source VARCHAR(80) NOT NULL DEFAULT 'manual', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY uniq_media_id (media_id), KEY idx_sku_hash (sku, image_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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

    private function beat(PDO $pdo, array $data): void
    {
        try { $pdo->prepare('INSERT INTO sv_agent_heartbeats (agent, status, summary_json, created_at) VALUES (?, ?, ?, NOW())')->execute(['media_memory', $data['ok'] ? 'ok' : 'warning', json_encode($data)]); } catch (Throwable $ignored) {}
    }
}
