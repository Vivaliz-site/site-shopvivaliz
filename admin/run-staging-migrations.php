<?php

declare(strict_types=1);

/**
 * Runner de migration único e IDEMPOTENTE para as duas tabelas de staging dos
 * módulos novos (AI Image Studio e Otimização de Cadastro), que existiam como
 * SQL no repo (database/migrations/) mas nunca foram aplicadas em produção —
 * causa raiz dos HTTP 500 relatados em admin_dashboard.php / admin_catalog.php.
 *
 * Não há acesso SSH/CLI à VM disponível para rodar os scripts de migration
 * padrão do projeto (scripts/migrate-*.php, que usam mysqli CLI) — por isso
 * este runner é HTTP, protegido por admin-guard, e seguro para rodar mais de
 * uma vez (usa CREATE TABLE IF NOT EXISTS, nunca DROP/ALTER destrutivo).
 *
 * Uso: abrir esta URL logado como admin, uma única vez (ou quantas vezes
 * quiser — é um no-op se as tabelas já existirem):
 *   https://shopvivaliz.com.br/admin/run-staging-migrations.php
 */

require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/pdo-database.php';

header('Content-Type: application/json; charset=utf-8');

$db = sv_pdo();
if (!$db instanceof PDO) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'erro' => 'Banco de dados indisponível.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$statements = [
    'product_images_staging' => "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'catalog_optimizations_staging' => "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
];

$results = [];
$allOk = true;

foreach ($statements as $tableName => $sql) {
    try {
        $db->exec($sql);
        $countStmt = $db->query('SELECT COUNT(*) AS c FROM `' . $tableName . '`');
        $count = $countStmt !== false ? (int) $countStmt->fetchColumn() : null;
        $results[$tableName] = ['ok' => true, 'linhas_existentes' => $count];
    } catch (Throwable $e) {
        $allOk = false;
        $results[$tableName] = ['ok' => false, 'erro' => $e->getMessage()];
        error_log('[run-staging-migrations] Falha ao criar ' . $tableName . ': ' . $e->getMessage());
    }
}

http_response_code($allOk ? 200 : 500);
echo json_encode(['ok' => $allOk, 'tabelas' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
