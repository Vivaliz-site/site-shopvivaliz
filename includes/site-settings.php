<?php
declare(strict_types=1);

require_once __DIR__ . '/pdo-database.php';

function sv_settings_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo = sv_pdo();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function sv_setting_get(string $key, ?string $default = null): ?string
{
    sv_settings_ensure_schema();
    try {
        $pdo = sv_pdo();
        $stmt = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = :k LIMIT 1');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['setting_value'] : $default;
    } catch (Throwable $e) {
        error_log('[SiteSettings] get failed: ' . $e->getMessage());
        return $default;
    }
}

function sv_setting_set(string $key, string $value): void
{
    sv_settings_ensure_schema();
    $pdo = sv_pdo();
    $stmt = $pdo->prepare(
        'INSERT INTO site_settings (setting_key, setting_value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([':k' => $key, ':v' => $value]);
}

/** Frete gratis fica DESABILITADO por padrao ate ser configurado explicitamente no admin. */
function sv_free_shipping_config(): array
{
    $enabled = sv_setting_get('free_shipping_enabled', '0') === '1';
    $threshold = (float)(sv_setting_get('free_shipping_threshold', '0') ?? '0');
    return ['enabled' => $enabled, 'threshold' => $threshold];
}
