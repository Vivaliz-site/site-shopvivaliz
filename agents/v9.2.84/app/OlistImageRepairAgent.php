<?php

declare(strict_types=1);

final class ShopvivalizOlistImageRepairAgent
{
    public function run(array $options = []): array
    {
        $startedAt = date('c');
        $actions = [];
        $errors = [];
        $pdo = $this->pdo();
        if (!$pdo) return $this->result(false, $startedAt, $actions, [['step' => 'pdo', 'message' => 'PDO indisponivel']]);

        try {
            if (!$this->tableExists($pdo, 'olist_products') || !$this->tableExists($pdo, 'olist_product_images')) {
                return $this->result(false, $startedAt, $actions, [['step' => 'schema', 'message' => 'Tabelas Olist ausentes']]);
            }

            $this->exec($pdo, "UPDATE olist_product_images i JOIN (SELECT MIN(id) id FROM olist_product_images WHERE status = 'active' AND product_local_id IS NOT NULL GROUP BY product_local_id) x ON x.id = i.id SET i.is_primary = 1 WHERE COALESCE(i.is_primary,0) = 0", $actions, 'mark_primary_images');

            if ($this->columnExists($pdo, 'olist_products', 'images_count')) {
                $this->exec($pdo, "UPDATE olist_products p LEFT JOIN (SELECT product_local_id, COUNT(*) qty FROM olist_product_images WHERE status = 'active' AND product_local_id IS NOT NULL GROUP BY product_local_id) i ON i.product_local_id = p.id SET p.images_count = COALESCE(i.qty,0)", $actions, 'refresh_images_count');
            }

            if ($this->columnExists($pdo, 'olist_products', 'primary_image_url')) {
                $this->exec($pdo, "UPDATE olist_products p JOIN (SELECT product_local_id, MIN(CASE WHEN is_primary = 1 THEN image_url ELSE NULL END) primary_url, MIN(image_url) fallback_url FROM olist_product_images WHERE status = 'active' AND product_local_id IS NOT NULL GROUP BY product_local_id) i ON i.product_local_id = p.id SET p.primary_image_url = COALESCE(NULLIF(i.primary_url,''), NULLIF(i.fallback_url,''), p.primary_image_url) WHERE p.primary_image_url IS NULL OR p.primary_image_url = ''", $actions, 'repair_primary_image_url');
            }

            if ($this->columnExists($pdo, 'olist_products', 'image_sync_status')) {
                $this->exec($pdo, "UPDATE olist_products SET image_sync_status = CASE WHEN COALESCE(images_count,0) > 0 THEN 'linked' ELSE COALESCE(image_sync_status, 'missing') END", $actions, 'refresh_image_sync_status');
            }

            if ($this->columnExists($pdo, 'olist_products', 'last_image_sync_at')) {
                $this->exec($pdo, "UPDATE olist_products SET last_image_sync_at = NOW() WHERE COALESCE(images_count,0) > 0 AND last_image_sync_at IS NULL", $actions, 'stamp_last_image_sync_at');
            }

            $stats = $this->stats($pdo);
            $actions[] = ['step' => 'stats', 'status' => 'ok', 'data' => $stats];
            $result = $this->result(true, $startedAt, $actions, $errors);
            $this->heartbeat($pdo, 'olist_image_repair', 'ok', $result);
            return $result;
        } catch (Throwable $e) {
            $errors[] = ['step' => 'run', 'message' => $e->getMessage()];
            $result = $this->result(false, $startedAt, $actions, $errors);
            $this->heartbeat($pdo, 'olist_image_repair', 'error', $result);
            return $result;
        }
    }

    private function result(bool $ok, string $startedAt, array $actions, array $errors): array
    {
        return ['ok' => $ok, 'agent' => 'olist_image_repair', 'started_at' => $startedAt, 'finished_at' => date('c'), 'actions' => $actions, 'errors' => $errors];
    }

    private function pdo(): ?PDO
    {
        foreach (['sv_pdo', 'sv_db', 'db', 'get_pdo'] as $fn) {
            if (function_exists($fn)) {
                $db = $fn();
                if ($db instanceof PDO) return $db;
            }
        }
        return null;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    }

    private function exec(PDO $pdo, string $sql, array &$actions, string $label): void
    {
        $affected = $pdo->exec($sql);
        $actions[] = ['step' => $label, 'status' => 'ok', 'affected' => $affected];
    }

    private function stats(PDO $pdo): array
    {
        $out = [];
        foreach ([
            'products_total' => 'SELECT COUNT(*) FROM olist_products',
            'products_with_image' => 'SELECT COUNT(*) FROM olist_products WHERE primary_image_url IS NOT NULL AND primary_image_url <> '''',
            'products_without_image' => 'SELECT COUNT(*) FROM olist_products WHERE primary_image_url IS NULL OR primary_image_url = ''''',
            'images_total' => 'SELECT COUNT(*) FROM olist_product_images WHERE status = ''active'''
        ] as $key => $sql) {
            try { $out[$key] = (int)$pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { $out[$key] = null; }
        }
        return $out;
    }

    private function heartbeat(PDO $pdo, string $agent, string $status, array $summary): void
    {
        try {
            $pdo->prepare('INSERT INTO sv_agent_heartbeats (agent, status, summary_json, created_at) VALUES (?, ?, ?, NOW())')->execute([$agent, $status, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        } catch (Throwable $ignored) {}
    }
}
