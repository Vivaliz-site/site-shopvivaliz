-- ShopVivaliz omnichannel publication hardening
-- Text and image approvals only become published after a confirmed channel write.
-- Price and inventory are intentionally absent from every table below.

ALTER TABLE `catalog_optimizations_staging`
    MODIFY COLUMN `status` ENUM(
        'pending',
        'failed',
        'publishing',
        'published',
        'publication_failed',
        'rejected',
        'approved'
    ) NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS `external_id` VARCHAR(190) NULL AFTER `error_message`,
    ADD COLUMN IF NOT EXISTS `publication_result_json` MEDIUMTEXT NULL AFTER `external_id`,
    ADD COLUMN IF NOT EXISTS `published_at` DATETIME NULL AFTER `publication_result_json`;

ALTER TABLE `product_images_staging`
    MODIFY COLUMN `status` ENUM(
        'pending',
        'failed',
        'publishing',
        'published',
        'publication_failed',
        'rejected',
        'approved'
    ) NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS `target_channel` ENUM('site','ml','shopee','amazon','tiktok','erp') NOT NULL DEFAULT 'site' AFTER `image_type`,
    ADD COLUMN IF NOT EXISTS `external_id` VARCHAR(190) NULL AFTER `error_message`,
    ADD COLUMN IF NOT EXISTS `publication_result_json` MEDIUMTEXT NULL AFTER `external_id`,
    ADD COLUMN IF NOT EXISTS `published_at` DATETIME NULL AFTER `publication_result_json`;

CREATE TABLE IF NOT EXISTS `product_channel_content` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `channel` ENUM('site','ml','shopee','amazon','tiktok','erp') NOT NULL,
    `title` VARCHAR(500) NOT NULL,
    `description` MEDIUMTEXT NOT NULL,
    `bullet_points_json` MEDIUMTEXT NOT NULL,
    `seo_keywords` TEXT NOT NULL,
    `marketing_hooks` TEXT NOT NULL,
    `meta_title` VARCHAR(500) NOT NULL,
    `meta_description` TEXT NOT NULL,
    `image_urls_json` MEDIUMTEXT NOT NULL,
    `status` ENUM('draft','publishing','published','publication_failed') NOT NULL DEFAULT 'draft',
    `external_id` VARCHAR(190) NULL,
    `last_error` VARCHAR(2000) NULL,
    `last_response_json` MEDIUMTEXT NULL,
    `published_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_product_channel_content` (`product_id`, `channel`),
    KEY `idx_product_channel_content_status` (`status`),
    KEY `idx_product_channel_content_external` (`channel`, `external_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketplace_product_links` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `channel` ENUM('ml','shopee','amazon','tiktok','erp') NOT NULL,
    `sku` VARCHAR(190) NOT NULL,
    `external_id` VARCHAR(190) NOT NULL,
    `external_parent_id` VARCHAR(190) NULL,
    `metadata_json` MEDIUMTEXT NULL,
    `verified_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_marketplace_product_link_product` (`product_id`, `channel`),
    UNIQUE KEY `uq_marketplace_product_link_external` (`channel`, `external_id`),
    KEY `idx_marketplace_product_link_sku` (`channel`, `sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `publication_attempts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `staging_type` ENUM('text','image') NOT NULL,
    `staging_id` BIGINT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `channel` ENUM('site','ml','shopee','amazon','tiktok','erp') NOT NULL,
    `action` VARCHAR(80) NOT NULL,
    `status` ENUM('started','published','failed') NOT NULL,
    `external_id` VARCHAR(190) NULL,
    `request_summary_json` MEDIUMTEXT NULL,
    `response_summary_json` MEDIUMTEXT NULL,
    `error_message` VARCHAR(2000) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_publication_attempt_staging` (`staging_type`, `staging_id`),
    KEY `idx_publication_attempt_product` (`product_id`, `channel`),
    KEY `idx_publication_attempt_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
