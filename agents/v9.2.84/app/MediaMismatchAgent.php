<?php

declare(strict_types=1);

final class ShopvivalizMediaMismatchAgent
{
    public function run(array $options = []): array
    {
        $pdo = $this->pdo();
        $limit = max(10, min(300, (int)($options['limit'] ?? 100)));
        $out = ['ok' => false, 'agent' => 'media_mismatch', 'generated_at' => date('c'), 'summary' => [], 'items' => [], 'errors' => []];
        if (!$pdo) {
            $out['errors'][] = 'database unavailable';
            return $out;
        }
        try {
            $rows = $this->rows($pdo, $limit);
            foreach ($rows as $row) {
                $risk = $this->risk($row);
                if ($risk['score'] >= 35) {
                    $out['items'][] = [
                        'product_id' => (int)($row['product_id'] ?? 0),
                        'media_id' => (int)($row['media_id'] ?? 0),
                        'sku' => (string)($row['sku'] ?? ''),
                        'position' => (int)($row['position'] ?? 0),
                        'score' => $risk['score'],
                        'reasons' => $risk['reasons'],
                    ];
                }
            }
            $out['summary'] = ['checked' => count($rows), 'flagged' => count($out['items'])];
            $out['ok'] = true;
        } catch (Throwable $e) {
            $out['errors'][] = $e->getMessage();
        }
        $this->beat($pdo, $out);
        return $out;
    }

    private function rows(PDO $pdo, int $limit): array
    {
        $sql = "SELECT p.id product_id, p.sku, p.name, p.category_name, m.id media_id, m.position, m.is_primary, m.image_url
                FROM olist_product_images m
                LEFT JOIN olist_products p ON p.id = m.product_local_id
                WHERE m.status = 'active'
                ORDER BY p.id ASC, m.position ASC
                LIMIT " . (int)$limit;
        $stmt = $pdo->query($sql);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    private function risk(array $row): array
    {
        $score = 0;
        $reasons = [];
        $url = strtolower((string)($row['image_url'] ?? ''));
        $name = strtolower((string)($row['name'] ?? ''));
        $category = strtolower((string)($row['category_name'] ?? ''));
        $position = (int)($row['position'] ?? 0);

        if ((int)($row['product_id'] ?? 0) <= 0) {
            $score += 55;
            $reasons[] = 'no_product_link';
        }
        if ($position >= 3) {
            $score += 15;
            $reasons[] = 'late_position_review';
        }
        if (str_contains($url, 'placeholder') || str_contains($url, 'default')) {
            $score += 45;
            $reasons[] = 'generic_asset';
        }
        $tokens = $this->tokens($name . ' ' . $category);
        if (count($tokens) >= 3) {
            $hits = 0;
            foreach ($tokens as $token) {
                if (str_contains($url, $token)) $hits++;
            }
            if ($hits === 0) {
                $score += 25;
                $reasons[] = 'low_name_url_match';
            }
        }
        return ['score' => min(100, $score), 'reasons' => $reasons];
    }

    private function tokens(string $text): array
    {
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text) ?: '';
        $raw = preg_split('/\s+/', trim($text)) ?: [];
        $stop = ['para','com','sem','das','dos','kit','und','azul','preto','branco','rosa'];
        return array_values(array_filter(array_unique($raw), fn($x) => strlen($x) >= 4 && !in_array($x, $stop, true)));
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
        try { $pdo->prepare('INSERT INTO sv_agent_heartbeats (agent, status, summary_json, created_at) VALUES (?, ?, ?, NOW())')->execute(['media_mismatch', $data['ok'] ? 'ok' : 'warning', json_encode($data)]); } catch (Throwable $ignored) {}
    }
}
