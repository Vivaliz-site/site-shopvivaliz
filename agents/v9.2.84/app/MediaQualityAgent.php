<?php

declare(strict_types=1);

final class ShopvivalizMediaQualityAgent
{
    public function run(array $options = []): array
    {
        $pdo = $this->pdo();
        $data = [
            'ok' => false,
            'agent' => 'media_quality',
            'generated_at' => date('c'),
            'summary' => [],
            'recommendations' => [],
            'errors' => [],
        ];
        if (!$pdo) {
            $data['errors'][] = 'database unavailable';
            return $data;
        }
        try {
            $data['summary']['items_total'] = $this->count($pdo, 'SELECT COUNT(*) FROM olist_products');
            $data['summary']['items_ready'] = $this->count($pdo, "SELECT COUNT(*) FROM olist_products WHERE primary_image_url IS NOT NULL AND primary_image_url <> ''");
            $data['summary']['items_pending'] = $this->count($pdo, "SELECT COUNT(*) FROM olist_products WHERE primary_image_url IS NULL OR primary_image_url = ''");
            $data['summary']['media_total'] = $this->count($pdo, "SELECT COUNT(*) FROM olist_product_images WHERE status = 'active'");
            $data['summary']['media_without_item'] = $this->count($pdo, 'SELECT COUNT(*) FROM olist_product_images WHERE product_local_id IS NULL OR product_local_id = 0');
            $data['summary']['items_with_more_than_10_media'] = $this->count($pdo, "SELECT COUNT(*) FROM (SELECT product_local_id FROM olist_product_images WHERE status = 'active' GROUP BY product_local_id HAVING COUNT(*) > 10) x");
            if (($data['summary']['items_pending'] ?? 0) > 0) {
                $data['recommendations'][] = 'Run media repair and import cycle again.';
            }
            if (($data['summary']['media_without_item'] ?? 0) > 0) {
                $data['recommendations'][] = 'Run media relation repair before next report.';
            }
            $data['ok'] = true;
        } catch (Throwable $e) {
            $data['errors'][] = $e->getMessage();
        }
        $this->beat($pdo, $data);
        return $data;
    }

    private function count(PDO $pdo, string $sql): ?int
    {
        try { return (int)$pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return null; }
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
        try {
            $pdo->prepare('INSERT INTO sv_agent_heartbeats (agent, status, summary_json, created_at) VALUES (?, ?, ?, NOW())')->execute(['media_quality', $data['ok'] ? 'ok' : 'warning', json_encode($data)]);
        } catch (Throwable $ignored) {}
    }
}
