-- Migration: Add stock_alerts table for Task-033
-- 2026-07-12

CREATE TABLE IF NOT EXISTS stock_alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    unsubscribe_token VARCHAR(64) NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    notified_at DATETIME,
    notified_count INTEGER DEFAULT 0,

    UNIQUE(sku, email),
    FOREIGN KEY(sku) REFERENCES products(sku)
);

-- Índices para performance
CREATE INDEX IF NOT EXISTS idx_stock_alerts_sku ON stock_alerts(sku);
CREATE INDEX IF NOT EXISTS idx_stock_alerts_email ON stock_alerts(email);
CREATE INDEX IF NOT EXISTS idx_stock_alerts_notified ON stock_alerts(notified_at);
CREATE INDEX IF NOT EXISTS idx_stock_alerts_unsubscribe ON stock_alerts(unsubscribe_token);
