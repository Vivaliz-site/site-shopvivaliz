-- Migration: product_images_staging
-- Data: 2026-08-05
-- Objetivo: fila de staging para imagens de produto geradas por IA (OpenAI,
-- Google Imagen ou Claude-otimizado -> OpenAI/Google), aguardando aprovação
-- manual do admin antes de irem para a tabela oficial de produtos.
--
-- Uso: mysql -u <user> -p <database> < database/migrations/2026-08-05-create-product-images-staging.sql
--
-- NOTA: `product_id` NÃO tem FOREIGN KEY para `produtos`.id neste script.
-- O tipo exato de `produtos`.id (INT vs BIGINT, signed vs unsigned) não pôde
-- ser confirmado a partir deste checkout (tabela `produtos` foi criada fora
-- do histórico de migrations rastreado aqui). Uma FK com tipo incompatível
-- falha a migration inteira em produção. Se quiser adicionar a constraint
-- depois, confirme o tipo real com `SHOW CREATE TABLE produtos;` primeiro e
-- rode um ALTER TABLE separado.

CREATE TABLE IF NOT EXISTS `product_images_staging` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `image_type` ENUM('white', 'hero', 'ambient') NOT NULL,
    `provider_used` ENUM('openai', 'google', 'claude_optimized') NOT NULL,
    `local_path` VARCHAR(500) NOT NULL,
    `prompt_used` TEXT NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `error_message` VARCHAR(1000) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_product_images_staging_product_id` (`product_id`),
    KEY `idx_product_images_staging_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
