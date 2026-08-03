CREATE TABLE IF NOT EXISTS email_marketing_suppressions (
    email_hash CHAR(64) NOT NULL PRIMARY KEY,
    reason VARCHAR(64) NOT NULL DEFAULT 'unsubscribe',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_campaign_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    campaign_key VARCHAR(190) NOT NULL,
    campaign_type VARCHAR(32) NOT NULL,
    status ENUM('running','completed','partial','failed') NOT NULL DEFAULT 'running',
    recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
    sent_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    payload_json JSON NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    UNIQUE KEY uq_email_campaign_runs_key (campaign_key),
    KEY idx_email_campaign_runs_type_started (campaign_type, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_campaign_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    campaign_key VARCHAR(190) NOT NULL,
    recipient_email_hash CHAR(64) NOT NULL,
    recipient_source VARCHAR(32) NOT NULL,
    status ENUM('sending','sent','failed','suppressed') NOT NULL,
    error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    UNIQUE KEY uq_email_campaign_delivery (campaign_key, recipient_email_hash),
    KEY idx_email_campaign_delivery_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
