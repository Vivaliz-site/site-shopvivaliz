-- Schema para A/B Testing de Layouts
-- Tabelas de suporte para variantes de layouts e tracking de conversões

-- ╔════════════════════════════════════════════════════════════════════════════╗
-- ║ Tabela: page_layout_variants                                              ║
-- ║ Propósito: Armazenar múltiplas variantes de um layout para A/B testing    ║
-- ╚════════════════════════════════════════════════════════════════════════════╝
CREATE TABLE IF NOT EXISTS `page_layout_variants` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `layout_id` INT UNSIGNED NOT NULL COMMENT 'FK para page_layouts.id',
    `page_id` VARCHAR(100) NOT NULL COMMENT 'Referência para compatibilidade',
    `variant_name` VARCHAR(50) NOT NULL COMMENT 'Nome da variante (ex: "Controle", "Teste A", "Teste B")',
    `variant_key` VARCHAR(100) NOT NULL COMMENT 'Key único para cookie (ex: "ab_variant_homepage_a")',
    `traffic_percent` DECIMAL(5, 2) NOT NULL DEFAULT 50.00 COMMENT 'Percentual de tráfego para esta variante (0-100)',
    `config` LONGTEXT NOT NULL COMMENT 'JSON do layout (cópia de page_layouts.config)',
    `active` TINYINT NOT NULL DEFAULT 1 COMMENT 'Ativa ou desativa a variante sem deletar',
    `impressions` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Vezes que a variante foi renderizada',
    `conversions` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Vezes que um pedido foi realizado com esta variante',
    `revenue` DECIMAL(12, 2) UNSIGNED DEFAULT 0.00 COMMENT 'Faturamento total desta variante',
    `created_by` INT COMMENT 'User ID que criou',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (`layout_id`) REFERENCES `page_layouts`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_variant_key` (`variant_key`),
    KEY `idx_page_id` (`page_id`),
    KEY `idx_active` (`active`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Variantes de layout para A/B testing';

-- ╔════════════════════════════════════════════════════════════════════════════╗
-- ║ Tabela: ab_variant_sessions                                               ║
-- ║ Propósito: Rastrear qual variante cada visitante vê (determinístico)      ║
-- ╚════════════════════════════════════════════════════════════════════════════╝
CREATE TABLE IF NOT EXISTS `ab_variant_sessions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `variant_id` INT UNSIGNED NOT NULL COMMENT 'FK para page_layout_variants.id',
    `page_id` VARCHAR(100) NOT NULL,
    `session_id` VARCHAR(128) COMMENT 'HASH do IP/Cookie para determinismo',
    `user_id` INT UNSIGNED COMMENT 'FK para usuarios (se logado)',
    `ip_address` VARCHAR(45) COMMENT 'IP do visitante (IPv4 ou IPv6)',
    `user_agent` VARCHAR(500) COMMENT 'User-Agent do navegador',
    `converted` TINYINT DEFAULT 0 COMMENT 'Fez alguma conversão?',
    `conversion_value` DECIMAL(10, 2) DEFAULT NULL COMMENT 'Valor do pedido se converteu',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `converted_at` DATETIME DEFAULT NULL,

    FOREIGN KEY (`variant_id`) REFERENCES `page_layout_variants`(`id`) ON DELETE CASCADE,
    KEY `idx_session_id` (`session_id`),
    KEY `idx_page_id_created` (`page_id`, `created_at`),
    KEY `idx_converted` (`converted`),
    KEY `idx_conversion_date` (`converted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Rastreamento de visitantes por variante';

-- ╔════════════════════════════════════════════════════════════════════════════╗
-- ║ Índices para Performance                                                  ║
-- ╚════════════════════════════════════════════════════════════════════════════╝

-- Para queries rápidas de CTR/conversão por variante
CREATE INDEX `idx_variant_conversions` ON `page_layout_variants` (`id`, `impressions`, `conversions`);

-- Para histórico de conversão por período
CREATE INDEX `idx_session_variant_period` ON `ab_variant_sessions` (`variant_id`, `converted`, `created_at`);

-- ╔════════════════════════════════════════════════════════════════════════════╗
-- ║ Dados Iniciais (Opcional)                                                 ║
-- ╚════════════════════════════════════════════════════════════════════════════╝

-- Nota: Os dados de teste serão inseridos via LayoutManager::createVariant()
-- em vez de hardcoded aqui, para manter schema limpo.
