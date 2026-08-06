<?php
declare(strict_types=1);

/**
 * Auditoria de prontidão das rotinas de publicação real.
 * Nunca imprime valores de tokens; somente nomes, presença e contagens.
 */
require_once dirname(__DIR__, 2) . '/config/constants.php';
require_once dirname(__DIR__, 2) . '/includes/pdo-database.php';
require_once dirname(__DIR__, 2) . '/includes/catalog-publication-schema.php';

function svmpr_env(string ...$keys): string
{
    foreach ($keys as $key) {
        $value = getenv($key);
        if (is_string($value) && trim($value) !== '') return trim($value);
        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && trim($_ENV[$key]) !== '') return trim($_ENV[$key]);
    }
    return '';
}

function svmpr_valid(string $value): bool
{
    $normalized = strtolower(trim($value));
    if ($normalized === '' || strlen($value) < 4) return false;
    foreach (['changeme', 'placeholder', 'your_', 'replace_', 'example', 'dummy', 'undefined', 'null', 'none'] as $marker) {
        if (str_contains($normalized, $marker)) return false;
    }
    return true;
}

$requirements = [
    'ml' => [
        ['ML_ACCESS_TOKEN', 'MERCADO_LIVRE_ACCESS_TOKEN'],
        ['ML_CLIENT_ID', 'MERCADO_LIVRE_CLIENT_ID'],
        ['ML_CLIENT_SECRET', 'MERCADO_LIVRE_CLIENT_SECRET'],
    ],
    'shopee' => [
        ['SHOPEE_PARTNER_ID'], ['SHOPEE_PARTNER_KEY'], ['SHOPEE_ACCESS_TOKEN'], ['SHOPEE_SHOP_ID'],
    ],
    'tiktok' => [
        ['TIKTOK_APP_KEY', 'TIKTOK_CLIENT_ID'], ['TIKTOK_APP_SECRET', 'TIKTOK_CLIENT_SECRET'],
        ['TIKTOK_ACCESS_TOKEN'], ['TIKTOK_SHOP_CIPHER', 'TIKTOK_SHOP_ID'],
    ],
    'amazon' => [
        ['AMAZON_LWA_CLIENT_ID'], ['AMAZON_LWA_CLIENT_SECRET'], ['AMAZON_LWA_REFRESH_TOKEN'],
        ['AMAZON_SELLER_ID', 'AMAZON_ACCOUNT_ID'], ['AMAZON_MARKETPLACE_ID'],
    ],
    'erp' => [
        ['OLIST_CLIENT_ID', 'TINY_CLIENT_ID', 'CLIENT_ID_API_OLIST'],
        ['OLIST_CLIENT_SECRET', 'TINY_CLIENT_SECRET', 'CLIENT_SECRET_OLIST'],
        ['OLIST_REFRESH_TOKEN', 'TINY_REFRESH_TOKEN'],
        ['OLIST_API_KEY', 'TOKEN_API_OLIST'],
    ],
];

$report = ['ok' => true, 'checked_at' => gmdate('c'), 'channels' => [], 'database' => []];
foreach ($requirements as $channel => $groups) {
    $missing = [];
    foreach ($groups as $aliases) {
        $value = svmpr_env(...$aliases);
        if (!svmpr_valid($value)) $missing[] = implode('/', $aliases);
    }
    $report['channels'][$channel] = ['ready' => $missing === [], 'missing_or_invalid' => $missing];
    if ($missing !== []) $report['ok'] = false;
}

$db = sv_pdo();
if (!$db instanceof PDO) {
    $report['ok'] = false;
    $report['database'] = ['ready' => false, 'error' => 'database_unavailable'];
} else {
    try {
        svcp_ensure_schema($db);
        $tables = ['product_channel_content', 'product_channel_mappings', 'catalog_publications', 'product_images'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int)$db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        }
        $mappingCounts = [];
        $stmt = $db->query('SELECT channel, COUNT(*) total FROM product_channel_mappings GROUP BY channel');
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $mappingCounts[(string)$row['channel']] = (int)$row['total'];
        }
        $missingSku = (int)$db->query("SELECT COUNT(*) FROM products WHERE sku IS NULL OR TRIM(sku) = ''")->fetchColumn();
        $missingOlistId = (int)$db->query("SELECT COUNT(*) FROM products WHERE olist_id IS NULL OR TRIM(olist_id) = ''")->fetchColumn();
        $report['database'] = [
            'ready' => true,
            'tables' => $counts,
            'mapping_counts' => $mappingCounts,
            'products_missing_sku' => $missingSku,
            'products_missing_olist_id' => $missingOlistId,
        ];
        if ($missingSku > 0) {
            $report['database']['warning'] = 'products_missing_sku_cannot_be_published_to_marketplaces';
        }
    } catch (Throwable $e) {
        $report['ok'] = false;
        $report['database'] = ['ready' => false, 'error' => get_class($e) . ':' . $e->getMessage()];
    }
}

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo (is_string($json) ? $json : '{"ok":false}') . PHP_EOL;
exit($report['ok'] ? 0 : 2);
