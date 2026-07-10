<?php
/**
 * Admin Dashboard Helpers - Queries e funções reutilizáveis
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class AdminHelpers {
    private static $db = null;

    private static function getDb() {
        if (self::$db === null) {
            self::$db = Database::getInstance()->getConnection();
        }
        return self::$db;
    }

    /**
     * Obter todas as variantes de uma página com estatísticas
     */
    public static function getPageVariants(string $pageId): array {
        $db = self::getDb();
        $pageId = $db->real_escape_string($pageId);

        $query = "
            SELECT
                id,
                page_id,
                variant_name,
                variant_type,
                impressions,
                clicks,
                conversions,
                revenue,
                status,
                ROUND(CASE WHEN impressions > 0 THEN (clicks * 100.0 / impressions) ELSE 0 END, 2) as ctr_percentage,
                ROUND(CASE WHEN clicks > 0 THEN (conversions * 100.0 / clicks) ELSE 0 END, 2) as conversion_rate,
                ROUND(CASE WHEN conversions > 0 THEN (revenue / conversions) ELSE 0 END, 2) as avg_order_value,
                created_at,
                started_at,
                ended_at
            FROM page_layout_variants
            WHERE page_id = '$pageId'
            ORDER BY impressions DESC, conversions DESC
        ";

        $result = $db->query($query);
        $variants = [];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $variants[] = $row;
            }
        }

        return $variants;
    }

    /**
     * Obter lista de todas as páginas com testes ativos
     */
    public static function getActivePagesWithTests(): array {
        $db = self::getDb();

        $query = "
            SELECT DISTINCT
                page_id,
                COUNT(id) as variant_count,
                SUM(impressions) as total_impressions,
                SUM(conversions) as total_conversions,
                MAX(started_at) as test_started_at
            FROM page_layout_variants
            WHERE status = 'active'
            GROUP BY page_id
            ORDER BY total_impressions DESC
        ";

        $result = $db->query($query);
        $pages = [];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $pages[] = $row;
            }
        }

        return $pages;
    }

    /**
     * Obter histórico de eventos para um período
     */
    public static function getEventHistory(string $pageId, int $days = 7): array {
        $db = self::getDb();
        $pageId = $db->real_escape_string($pageId);
        $startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $query = "
            SELECT
                DATE(ate.created_at) as date,
                ate.event_type,
                COUNT(*) as count
            FROM ab_test_events ate
            WHERE ate.page_id = '$pageId'
            AND ate.created_at >= '$startDate'
            GROUP BY DATE(ate.created_at), ate.event_type
            ORDER BY date ASC, event_type ASC
        ";

        $result = $db->query($query);
        $history = [];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $history[] = $row;
            }
        }

        return $history;
    }

    /**
     * Registrar um evento de A/B testing
     */
    public static function logEvent(int $variantId, string $pageId, string $eventType, ?string $sessionId = null): bool {
        $db = self::getDb();

        $variantId = (int)$variantId;
        $pageId = $db->real_escape_string($pageId);
        $eventType = $db->real_escape_string($eventType);
        $sessionId = $sessionId ? $db->real_escape_string($sessionId) : null;

        $sessionIdVal = $sessionId ? "'$sessionId'" : 'NULL';

        $query = "
            INSERT INTO ab_test_events
            (variant_id, page_id, event_type, session_id, user_agent)
            VALUES
            ($variantId, '$pageId', '$eventType', $sessionIdVal, '" .
            $db->real_escape_string($_SERVER['HTTP_USER_AGENT'] ?? '') . "')
        ";

        $result = $db->query($query);

        if ($result) {
            // Atualizar estatísticas da variante
            if ($eventType === 'impression') {
                $db->query("UPDATE page_layout_variants SET impressions = impressions + 1 WHERE id = $variantId");
            } elseif ($eventType === 'click') {
                $db->query("UPDATE page_layout_variants SET clicks = clicks + 1 WHERE id = $variantId");
            } elseif ($eventType === 'conversion') {
                $db->query("UPDATE page_layout_variants SET conversions = conversions + 1 WHERE id = $variantId");
            }
        }

        return (bool)$result;
    }

    /**
     * Comparar duas variantes de uma página
     */
    public static function compareVariants(int $variantIdA, int $variantIdB): array {
        $db = self::getDb();

        $query = "
            SELECT
                id,
                variant_name,
                impressions,
                clicks,
                conversions,
                revenue,
                ROUND(CASE WHEN impressions > 0 THEN (clicks * 100.0 / impressions) ELSE 0 END, 2) as ctr_percentage,
                ROUND(CASE WHEN clicks > 0 THEN (conversions * 100.0 / clicks) ELSE 0 END, 2) as conversion_rate,
                ROUND(CASE WHEN conversions > 0 THEN (revenue / conversions) ELSE 0 END, 2) as avg_order_value
            FROM page_layout_variants
            WHERE id IN ($variantIdA, $variantIdB)
        ";

        $result = $db->query($query);
        $comparison = [];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $comparison[$row['id']] = $row;
            }
        }

        return $comparison;
    }

    /**
     * Obter a variante vencedora (melhor CTR/conversão)
     */
    public static function getWinnerVariant(string $pageId): ?array {
        $db = self::getDb();
        $pageId = $db->real_escape_string($pageId);

        $query = "
            SELECT
                id,
                variant_name,
                variant_type,
                impressions,
                clicks,
                conversions,
                revenue,
                ROUND(CASE WHEN impressions > 0 THEN (clicks * 100.0 / impressions) ELSE 0 END, 2) as ctr_percentage,
                ROUND(CASE WHEN clicks > 0 THEN (conversions * 100.0 / clicks) ELSE 0 END, 2) as conversion_rate
            FROM page_layout_variants
            WHERE page_id = '$pageId'
            AND impressions >= 100
            ORDER BY conversion_rate DESC, ctr_percentage DESC
            LIMIT 1
        ";

        $result = $db->query($query);

        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }

        return null;
    }

    /**
     * Filtrar variantes por período
     */
    public static function getVariantsByPeriod(string $pageId, string $startDate, string $endDate): array {
        $db = self::getDb();
        $pageId = $db->real_escape_string($pageId);
        $startDate = $db->real_escape_string($startDate);
        $endDate = $db->real_escape_string($endDate);

        $query = "
            SELECT
                id,
                variant_name,
                variant_type,
                impressions,
                clicks,
                conversions,
                revenue,
                ROUND(CASE WHEN impressions > 0 THEN (clicks * 100.0 / impressions) ELSE 0 END, 2) as ctr_percentage,
                created_at
            FROM page_layout_variants
            WHERE page_id = '$pageId'
            AND created_at >= '$startDate'
            AND created_at <= '$endDate'
            ORDER BY created_at ASC
        ";

        $result = $db->query($query);
        $variants = [];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $variants[] = $row;
            }
        }

        return $variants;
    }

    /**
     * Exportar dados de variantes para CSV
     */
    public static function exportVariantsToCSV(string $pageId): string {
        $variants = self::getPageVariants($pageId);

        $csv = "Page ID,Variant Name,Type,Impressions,Clicks,Conversions,Revenue,CTR %,Conversion Rate %,AOV,Status,Created At\n";

        foreach ($variants as $v) {
            $csv .= sprintf(
                '"%s","%s","%s",%d,%d,%d,%.2f,%.2f,%.2f,"%s","%s","%s"' . "\n",
                $v['page_id'],
                $v['variant_name'],
                $v['variant_type'],
                $v['impressions'],
                $v['clicks'],
                $v['conversions'],
                $v['revenue'],
                $v['ctr_percentage'],
                $v['conversion_rate'],
                $v['avg_order_value'] ?? 0,
                $v['status'],
                $v['created_at']
            );
        }

        return $csv;
    }

    /**
     * Inserir variante de página
     */
    public static function createPageVariant(string $pageId, string $variantName, string $variantType = 'control', ?array $configJson = null): ?int {
        $db = self::getDb();

        $pageId = $db->real_escape_string($pageId);
        $variantName = $db->real_escape_string($variantName);
        $variantType = $db->real_escape_string($variantType);
        $configJson = $configJson ? json_encode($configJson) : null;
        $configVal = $configJson ? "'" . $db->real_escape_string($configJson) . "'" : 'NULL';

        $query = "
            INSERT INTO page_layout_variants
            (page_id, variant_name, variant_type, config_json, started_at)
            VALUES
            ('$pageId', '$variantName', '$variantType', $configVal, NOW())
            ON DUPLICATE KEY UPDATE updated_at = NOW()
        ";

        if ($db->query($query)) {
            return (int)$db->insert_id;
        }

        return null;
    }

    /**
     * Atualizar receita de uma variante (útil para pedidos capturados)
     */
    public static function addRevenueToVariant(int $variantId, float $amount): bool {
        $db = self::getDb();

        $variantId = (int)$variantId;
        $amount = (float)$amount;

        $query = "
            UPDATE page_layout_variants
            SET revenue = revenue + $amount,
                updated_at = NOW()
            WHERE id = $variantId
        ";

        return (bool)$db->query($query);
    }
}
