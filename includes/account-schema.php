<?php
declare(strict_types=1);

require_once __DIR__ . '/pdo-database.php';

function sv_account_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo = sv_pdo();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS addresses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            label VARCHAR(60) NOT NULL DEFAULT "Endereço",
            cep VARCHAR(9) NOT NULL,
            street VARCHAR(255) NOT NULL,
            number VARCHAR(20) NOT NULL,
            complement VARCHAR(120) NULL,
            neighborhood VARCHAR(120) NOT NULL,
            city VARCHAR(120) NOT NULL,
            state CHAR(2) NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_addresses_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    // A tabela `orders` real (usada por meus-pedidos.php e pelo webhook do ERP)
    // nunca era criada nem populada por nenhum fluxo de checkout em producao --
    // so existia em scripts de teste. Garantimos aqui a existencia com as
    // colunas ja assumidas pelo restante do codigo, mais items_json/order_number
    // para permitir recompra e listagem sem depender dos arquivos JSON soltos.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            order_number VARCHAR(50) NULL,
            olist_order_id VARCHAR(64) NULL,
            email VARCHAR(255) NULL,
            order_total DECIMAL(10,2) NOT NULL DEFAULT 0,
            order_status VARCHAR(50) NOT NULL DEFAULT "aguardando_pagamento",
            payment_method VARCHAR(50) NULL,
            tracking_number VARCHAR(120) NULL,
            estimated_delivery DATE NULL,
            items_json TEXT NULL,
            nf_pdf_url VARCHAR(500) NULL,
            nf_xml_url VARCHAR(500) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_orders_user (user_id),
            INDEX idx_orders_olist_id (olist_order_id),
            INDEX idx_orders_order_number (order_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    // A tabela `orders` de producao ja existia antes (criada por outro fluxo,
    // com schema divergente) entao o CREATE TABLE IF NOT EXISTS acima nao fez
    // nada. Garante aqui, coluna a coluna, tudo que o restante do codigo usa --
    // sem presumir posicao (AFTER x) ja que nem sempre a coluna de referencia existe.
    $existing = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM orders');
    foreach ($stmt->fetchAll() as $row) {
        $existing[$row['Field']] = true;
    }
    $alterations = [
        'user_id' => 'ALTER TABLE orders ADD COLUMN user_id INT NULL',
        'order_number' => 'ALTER TABLE orders ADD COLUMN order_number VARCHAR(50) NULL',
        'olist_order_id' => 'ALTER TABLE orders ADD COLUMN olist_order_id VARCHAR(64) NULL',
        'email' => 'ALTER TABLE orders ADD COLUMN email VARCHAR(255) NULL',
        'order_total' => 'ALTER TABLE orders ADD COLUMN order_total DECIMAL(10,2) NOT NULL DEFAULT 0',
        'order_status' => 'ALTER TABLE orders ADD COLUMN order_status VARCHAR(50) NOT NULL DEFAULT "aguardando_pagamento"',
        'payment_method' => 'ALTER TABLE orders ADD COLUMN payment_method VARCHAR(50) NULL',
        'tracking_number' => 'ALTER TABLE orders ADD COLUMN tracking_number VARCHAR(120) NULL',
        'estimated_delivery' => 'ALTER TABLE orders ADD COLUMN estimated_delivery DATE NULL',
        'items_json' => 'ALTER TABLE orders ADD COLUMN items_json TEXT NULL',
        'nf_pdf_url' => 'ALTER TABLE orders ADD COLUMN nf_pdf_url VARCHAR(500) NULL',
        'nf_xml_url' => 'ALTER TABLE orders ADD COLUMN nf_xml_url VARCHAR(500) NULL',
    ];
    foreach ($alterations as $column => $sql) {
        if (!isset($existing[$column])) {
            $pdo->exec($sql);
            $existing[$column] = true;
        }
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            used_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_resets_user (user_id),
            UNIQUE INDEX idx_password_resets_token (token_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $existingIndexes = [];
    $idxStmt = $pdo->query('SHOW INDEX FROM orders');
    foreach ($idxStmt->fetchAll() as $row) {
        $existingIndexes[$row['Key_name']] = true;
    }
    $indexAlterations = [
        'idx_orders_user' => 'ALTER TABLE orders ADD INDEX idx_orders_user (user_id)',
        'idx_orders_olist_id' => 'ALTER TABLE orders ADD INDEX idx_orders_olist_id (olist_order_id)',
        'idx_orders_order_number' => 'ALTER TABLE orders ADD INDEX idx_orders_order_number (order_number)',
    ];
    foreach ($indexAlterations as $index => $sql) {
        if (!isset($existingIndexes[$index])) {
            $pdo->exec($sql);
        }
    }
}
