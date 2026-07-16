<?php

declare(strict_types=1);

final class ShopvivalizSafeMigrationRepairAgent
{
    public function run(array $options = []): array
    {
        $startedAt = date('c');
        $repairs = [];
        $errors = [];
        $pdo = $this->pdo();

        if (!$pdo) {
            return $this->result(false, $startedAt, $repairs, [['step' => 'pdo', 'message' => 'PDO indisponivel']], 'PDO indisponivel; modo relatorio.');
        }

        $this->safeExec($pdo, "CREATE TABLE IF NOT EXISTS sv_agent_heartbeats (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, agent VARCHAR(80) NOT NULL, status VARCHAR(40) NOT NULL DEFAULT 'ok', summary_json LONGTEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_agent_created (agent, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $repairs, $errors, 'create sv_agent_heartbeats');
        $this->safeExec($pdo, "CREATE TABLE IF NOT EXISTS sv_autonomous_agent_reports (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, report_key VARCHAR(80) NOT NULL, status VARCHAR(40) NOT NULL DEFAULT 'ok', report_json LONGTEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_report_created (report_key, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $repairs, $errors, 'create sv_autonomous_agent_reports');
        $this->safeExec($pdo, "CREATE TABLE IF NOT EXISTS sv_autonomous_loop_requests (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, source VARCHAR(80) NOT NULL, status VARCHAR(40) NOT NULL DEFAULT 'queued', payload_json LONGTEXT NULL, result_json LONGTEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL, PRIMARY KEY (id), KEY idx_status_created (status, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $repairs, $errors, 'create sv_autonomous_loop_requests');

        if ($this->tableExists($pdo, 'olist_product_images')) {
            $this->ensureIndex($pdo, 'olist_product_images', 'idx_sv_olist_images_product_status', 'ALTER TABLE olist_product_images ADD INDEX idx_sv_olist_images_product_status (product_local_id, status)', $repairs, $errors);
            $this->ensureIndex($pdo, 'olist_product_images', 'idx_sv_olist_images_olist_status', 'ALTER TABLE olist_product_images ADD INDEX idx_sv_olist_images_olist_status (olist_product_id, status)', $repairs, $errors);
            $this->ensureIndex($pdo, 'olist_product_images', 'idx_sv_olist_images_sku_status', 'ALTER TABLE olist_product_images ADD INDEX idx_sv_olist_images_sku_status (sku, status)', $repairs, $errors);
        }
        if ($this->tableExists($pdo, 'olist_products')) {
            $this->ensureIndex($pdo, 'olist_products', 'idx_sv_olist_products_olist_id', 'ALTER TABLE olist_products ADD INDEX idx_sv_olist_products_olist_id (olist_id)', $repairs, $errors);
            $this->ensureIndex($pdo, 'olist_products', 'idx_sv_olist_products_sku', 'ALTER TABLE olist_products ADD INDEX idx_sv_olist_products_sku (sku)', $repairs, $errors);
        }

        $result = $this->result(count($errors) === 0, $startedAt, $repairs, $errors, null);
        $this->heartbeat($pdo, 'safe_migration_repair', $result['ok'] ? 'ok' : 'warning', $result);
        return $result;
    }

    public function pdo(): ?PDO
    {
        foreach (['sv_pdo', 'sv_db', 'db', 'get_pdo'] as $fn) {
            if (function_exists($fn)) {
                $db = $fn();
                if ($db instanceof PDO) return $db;
            }
        }
        return null;
    }

    private function result(bool $ok, string $startedAt, array $repairs, array $errors, ?string $message): array
    {
        return ['ok' => $ok, 'agent' => 'safe_migration_repair', 'started_at' => $startedAt, 'finished_at' => date('c'), 'message' => $message, 'repairs' => $repairs, 'errors' => $errors];
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    private function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
        $stmt->execute([$table, $index]);
        return (bool)$stmt->fetchColumn();
    }

    private function ensureIndex(PDO $pdo, string $table, string $index, string $sql, array &$repairs, array &$errors): void
    {
        try {
            if ($this->indexExists($pdo, $table, $index)) {
                $repairs[] = ['step' => 'ensure_index', 'target' => $index, 'status' => 'already_exists'];
                return;
            }
            $pdo->exec($sql);
            $repairs[] = ['step' => 'ensure_index', 'target' => $index, 'status' => 'created'];
        } catch (Throwable $e) {
            if ($this->benign($e)) {
                $repairs[] = ['step' => 'ensure_index', 'target' => $index, 'status' => 'already_exists_after_race'];
                return;
            }
            $errors[] = ['step' => 'ensure_index', 'target' => $index, 'message' => $e->getMessage()];
        }
    }

    private function safeExec(PDO $pdo, string $sql, array &$repairs, array &$errors, string $label): void
    {
        try {
            $pdo->exec($sql);
            $repairs[] = ['step' => $label, 'status' => 'ok'];
        } catch (Throwable $e) {
            if ($this->benign($e)) {
                $repairs[] = ['step' => $label, 'status' => 'already_exists'];
                return;
            }
            $errors[] = ['step' => $label, 'message' => $e->getMessage()];
        }
    }

    private function benign(Throwable $e): bool
    {
        $mysql = $e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : (int)$e->getCode();
        return in_array($mysql, [1050, 1060, 1061, 1068, 1091, 1826], true) || (bool)preg_match('/already exists|duplicate (column|key|index)|check that column\/key exists/i', $e->getMessage());
    }

    private function heartbeat(PDO $pdo, string $agent, string $status, array $summary): void
    {
        try {
            $stmt = $pdo->prepare('INSERT INTO sv_agent_heartbeats (agent, status, summary_json, created_at) VALUES (?, ?, ?, NOW())');
            $stmt->execute([$agent, $status, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        } catch (Throwable $ignored) {}
    }
}
