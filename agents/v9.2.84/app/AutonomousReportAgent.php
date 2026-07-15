<?php

declare(strict_types=1);

final class ShopvivalizAutonomousReportAgent
{
    public const VERSION = '9.2.84-resident-autonomous-watchdog';

    public function run(array $options = []): array
    {
        $pdo = $this->pdo();
        $report = [
            'ok' => true,
            'agent' => 'autonomous_report',
            'version' => self::VERSION,
            'generated_at' => date('c'),
            'database_available' => (bool)$pdo,
            'tables' => [],
            'olist_images' => [],
            'heartbeats' => [],
            'next_actions' => [],
        ];

        if ($pdo) {
            $report['tables'] = $this->tableCounts($pdo);
            $report['olist_images'] = $this->olistImageStats($pdo);
            $report['heartbeats'] = $this->recentHeartbeats($pdo);
            $this->persist($pdo, $report);
        }

        if (($report['tables']['sv_autonomous_500_update_cycles']['rows'] ?? 0) <= 0) {
            $report['next_actions'][] = 'Acionar watchdog com run_loop=1.';
        }
        if (($report['olist_images']['products_without_image'] ?? 0) > 0) {
            $report['next_actions'][] = 'Continuar importacao e reparo de imagens Olist.';
        }

        return $report;
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

    private function tableCounts(PDO $pdo): array
    {
        $tables = ['olist_products','olist_product_images','sv_agent_heartbeats','sv_autonomous_agent_reports','sv_autonomous_loop_requests','sv_autonomous_500_update_batches','sv_autonomous_500_update_cycles'];
        $out = [];
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                $stmt->execute([$table]);
                if (!$stmt->fetchColumn()) {
                    $out[$table] = ['exists' => false, 'rows' => 0];
                    continue;
                }
                $out[$table] = ['exists' => true, 'rows' => (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn()];
            } catch (Throwable $e) {
                $out[$table] = ['exists' => null, 'rows' => 0, 'error' => $e->getMessage()];
            }
        }
        return $out;
    }

    private function olistImageStats(PDO $pdo): array
    {
        $out = [];
        $map = [
            'products_total' => 'SELECT COUNT(*) FROM olist_products',
            'products_with_image' => "SELECT COUNT(*) FROM olist_products WHERE primary_image_url IS NOT NULL AND primary_image_url <> ''",
            'products_without_image' => "SELECT COUNT(*) FROM olist_products WHERE primary_image_url IS NULL OR primary_image_url = ''",
            'images_total' => "SELECT COUNT(*) FROM olist_product_images WHERE status = 'active'"
        ];
        foreach ($map as $key => $sql) {
            try { $out[$key] = (int)$pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { $out[$key] = null; }
        }
        return $out;
    }

    private function recentHeartbeats(PDO $pdo): array
    {
        try {
            $stmt = $pdo->query('SELECT agent, status, created_at FROM sv_agent_heartbeats ORDER BY id DESC LIMIT 10');
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private function persist(PDO $pdo, array $report): void
    {
        try {
            $stmt = $pdo->prepare('INSERT INTO sv_autonomous_agent_reports (report_key, status, report_json, created_at) VALUES (?, ?, ?, NOW())');
            $stmt->execute(['autonomous_report', 'ok', json_encode($report)]);
        } catch (Throwable $ignored) {}
    }
}
