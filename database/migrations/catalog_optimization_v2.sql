-- Migration: catalog_optimizations_staging
-- Data: 2026-08-05
-- Objetivo: fila de staging para textos de cadastro de produto (título,
-- descrição, bullet points, SEO/GEO) reescritos por IA (OpenAI/Gemini/
-- Claude) por canal de venda, aguardando aprovação manual do admin antes de
-- irem para a tabela oficial de produtos.
--
-- IMPORTANTE: esta tabela NUNCA guarda preço nem estoque — só texto de
-- marketing/SEO. O core engine (api/optimize_catalog.php) nem lê essas
-- colunas da tabela de produtos, por design.
--
-- Uso: mysql -u <user> -p <database> < database/migrations/catalog_optimization_v2.sql
--
-- NOTA sobre FK: sem FOREIGN KEY para `produtos`.id pelo mesmo motivo já
-- documentado em 2026-08-05-create-product-images-staging.sql — o tipo
-- exato de `produtos`.id não foi confirmado neste checkout. Confirme com
-- `SHOW CREATE TABLE produtos;` antes de adicionar a constraint via ALTER
-- TABLE separado, se desejar.

CREATE TABLE IF NOT EXISTS `catalog_optimizations_staging` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `channel` ENUM('ml', 'shopee', 'amazon', 'tiktok', 'site', 'erp') NOT NULL,
    `provider_used` ENUM('openai', 'gemini', 'claude') NOT NULL,
    `optimized_title` VARCHAR(255) NOT NULL,
    `optimized_description` TEXT NOT NULL,
    `bullet_points_json` TEXT NOT NULL,
    `seo_keywords` TEXT NOT NULL,
    `marketing_hooks` TEXT NOT NULL,
    `meta_data_json` TEXT NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `error_message` VARCHAR(1000) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX (`product_id`),
    INDEX (`status`),
    INDEX (`channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
