<?php
declare(strict_types=1);

/**
 * Estruturas idempotentes usadas pelas rotinas de publicação real.
 * Nenhuma tabela abaixo armazena ou altera preço/estoque.
 */
function svcp_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function svcp_column_type(PDO $db, string $table, string $column): string
{
    $stmt = $db->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->execute([$table, $column]);
    return strtolower((string)$stmt->fetchColumn());
}

function svcp_add_column_if_missing(PDO $db, string $table, string $column, string $definition): void
{
    if (!svcp_column_exists($db, $table, $column)) {
        $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

function svcp_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `product_channel_content` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `channel` VARCHAR(32) NOT NULL,
    `title` VARCHAR(500) NOT NULL,
    `description` LONGTEXT NOT NULL,
    `bullet_points_json` LONGTEXT NOT NULL,
    `seo_keywords_json` LONGTEXT NOT NULL,
    `marketing_hooks_json` LONGTEXT NOT NULL,
    `meta_title` VARCHAR(500) NOT NULL DEFAULT '',
    `meta_description` TEXT NOT NULL,
    `attributes_json` LONGTEXT NULL,
    `images_json` LONGTEXT NULL,
    `published_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_product_channel_content` (`product_id`, `channel`),
    KEY `idx_product_channel_content_channel` (`channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `product_channel_mappings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `channel` VARCHAR(32) NOT NULL,
    `external_id` VARCHAR(255) NOT NULL,
    `seller_sku` VARCHAR(255) NOT NULL DEFAULT '',
    `metadata_json` LONGTEXT NULL,
    `last_verified_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_product_channel_mapping` (`product_id`, `channel`),
    KEY `idx_product_channel_external` (`channel`, `external_id`),
    KEY `idx_product_channel_sku` (`channel`, `seller_sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `catalog_publications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `publication_type` VARCHAR(16) NOT NULL,
    `staging_id` BIGINT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `channel` VARCHAR(32) NOT NULL,
    `status` VARCHAR(32) NOT NULL,
    `operation` VARCHAR(100) NOT NULL DEFAULT '',
    `external_id` VARCHAR(255) NOT NULL DEFAULT '',
    `http_status` INT NOT NULL DEFAULT 0,
    `request_id` VARCHAR(255) NOT NULL DEFAULT '',
    `fields_json` LONGTEXT NULL,
    `response_json` LONGTEXT NULL,
    `error_message` TEXT NULL,
    `submitted_at` DATETIME NULL,
    `verified_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_catalog_publications_staging` (`publication_type`, `staging_id`),
    KEY `idx_catalog_publications_product_channel` (`product_id`, `channel`),
    KEY `idx_catalog_publications_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $db->exec(<<<'SQL'
CREATE INDEX IF NOT EXISTS `idx_catalog_optimizations_staging_channel_product_status`
    ON `catalog_optimizations_staging` (`channel`, `product_id`, `status`, `created_at`, `id`)
SQL);

    $db->exec(<<<'SQL'
CREATE INDEX IF NOT EXISTS `idx_catalog_optimizations_staging_status_created`
    ON `catalog_optimizations_staging` (`status`, `created_at`, `id`)
SQL);

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `product_images` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `image_type` VARCHAR(32) NOT NULL,
    `public_url` VARCHAR(1000) NOT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `source_staging_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_product_image_type_url` (`product_id`, `image_type`, `public_url`(255)),
    KEY `idx_product_images_product` (`product_id`, `is_primary`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    if (svcp_column_exists($db, 'catalog_optimizations_staging', 'status')) {
        $statusType = svcp_column_type($db, 'catalog_optimizations_staging', 'status');
        if (!str_starts_with($statusType, 'varchar(')) {
            $db->exec("ALTER TABLE `catalog_optimizations_staging` MODIFY `status` VARCHAR(32) NOT NULL DEFAULT 'pending'");
        }
        svcp_add_column_if_missing($db, 'catalog_optimizations_staging', 'publication_summary_json', 'LONGTEXT NULL AFTER `error_message`');
        svcp_add_column_if_missing($db, 'catalog_optimizations_staging', 'published_at', 'DATETIME NULL AFTER `publication_summary_json`');
    }

    if (svcp_column_exists($db, 'product_images_staging', 'status')) {
        $statusType = svcp_column_type($db, 'product_images_staging', 'status');
        if (!str_starts_with($statusType, 'varchar(')) {
            $db->exec("ALTER TABLE `product_images_staging` MODIFY `status` VARCHAR(32) NOT NULL DEFAULT 'pending'");
        }
        svcp_add_column_if_missing($db, 'product_images_staging', 'target_channels_json', 'LONGTEXT NULL AFTER `error_message`');
        svcp_add_column_if_missing($db, 'product_images_staging', 'publication_summary_json', 'LONGTEXT NULL AFTER `target_channels_json`');
        svcp_add_column_if_missing($db, 'product_images_staging', 'published_at', 'DATETIME NULL AFTER `publication_summary_json`');
    }

    $done = true;
}
