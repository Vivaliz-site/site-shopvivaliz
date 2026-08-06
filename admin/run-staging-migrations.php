<?php
declare(strict_types=1);

/**
 * Runner idempotente das estruturas de staging e publicação omnicanal.
 * Protegido por sessão administrativa e seguro para repetição.
 */
require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/pdo-database.php';
require_once __DIR__ . '/../includes/catalog-publication-schema.php';

header('Content-Type: application/json; charset=utf-8');

$db = sv_pdo();
if (!$db instanceof PDO) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'erro' => 'Banco de dados indisponível.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$results = [];
try {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `product_images_staging` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `image_type` VARCHAR(32) NOT NULL,
    `provider_used` VARCHAR(32) NOT NULL,
    `local_path` VARCHAR(500) NOT NULL,
    `prompt_used` TEXT NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
    `error_message` VARCHAR(1000) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_product_images_staging_product_id` (`product_id`),
    KEY `idx_product_images_staging_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $results['product_images_staging'] = ['ok' => true];

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `catalog_optimizations_staging` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `channel` VARCHAR(32) NOT NULL,
    `provider_used` VARCHAR(32) NOT NULL,
    `optimized_title` VARCHAR(500) NOT NULL,
    `optimized_description` LONGTEXT NOT NULL,
    `bullet_points_json` LONGTEXT NOT NULL,
    `seo_keywords` LONGTEXT NOT NULL,
    `marketing_hooks` LONGTEXT NOT NULL,
    `meta_data_json` LONGTEXT NOT NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
    `error_message` VARCHAR(1000) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_catalog_optimization_product` (`product_id`),
    KEY `idx_catalog_optimization_status` (`status`),
    KEY `idx_catalog_optimization_channel` (`channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $results['catalog_optimizations_staging'] = ['ok' => true];

    svcp_ensure_schema($db);
    foreach (['product_channel_content', 'product_channel_mappings', 'catalog_publications', 'product_images'] as $table) {
        $count = $db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        $results[$table] = ['ok' => true, 'linhas_existentes' => (int)$count];
    }

    http_response_code(200);
    echo json_encode(['ok' => true, 'tabelas' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[run-staging-migrations] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage(), 'tabelas' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
