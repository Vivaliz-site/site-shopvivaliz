-- ShopVivaliz storefront hardening
-- Idempotente: pode ser executado mais de uma vez.

CREATE TABLE IF NOT EXISTS inventory_reservation_locks (
    sku VARCHAR(100) NOT NULL PRIMARY KEY,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_reservations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reservation_key CHAR(64) NOT NULL,
    order_number VARCHAR(40) NULL,
    sku VARCHAR(100) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    source_stock INT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    payment_method VARCHAR(32) NOT NULL DEFAULT 'pix',
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inventory_reservation (reservation_key, sku),
    KEY idx_inventory_reservation_sku (sku, status, expires_at),
    KEY idx_inventory_reservation_order (order_number),
    KEY idx_inventory_reservation_expiry (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS newsletter_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    confirm_token_hash CHAR(64) NOT NULL,
    consent_at DATETIME NOT NULL,
    confirmed_at DATETIME NULL,
    source VARCHAR(80) NOT NULL DEFAULT 'site_home',
    ip_hash CHAR(64) NOT NULL,
    user_agent_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_newsletter_email (email),
    KEY idx_newsletter_status (status),
    KEY idx_newsletter_token (confirm_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
