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
}
