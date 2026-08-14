<?php
declare(strict_types=1);

require_once __DIR__ . '/pdo-database.php';

/**
 * PERF: `CREATE TABLE IF NOT EXISTS` rodava em toda requisição pública.
 * Agora só é executado em caminhos de escrita (admin) ou quando a leitura
 * em massa falha porque a tabela ainda não existe.
 */
function sv_settings_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo = sv_pdo();
    if (!$pdo) {
        return;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS site_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable $e) {
        error_log('[SiteSettings] ensure schema failed: ' . $e->getMessage());
    }
}

/**
 * Carrega TODAS as configurações em uma única query por requisição.
 *
 * Antes, cada sv_setting_get() abria prepare+execute próprios. A home fazia
 * ~10 leituras (frete grátis em index.php e novamente no navbar, mais 5
 * redes sociais no rodapé), cada uma com round-trip ao MySQL remoto.
 * Agora: 1 query por requisição, com cache em APCu (TTL 60s) quando
 * disponível — o que zera as consultas na maioria dos acessos.
 *
 * @return array<string,string>
 */
function sv_settings_all(bool $forceRefresh = false): array
{
    static $cache = null;

    if ($cache !== null && !$forceRefresh) {
        return $cache;
    }

    $apcu = function_exists('apcu_fetch') && function_exists('apcu_store');
    $apcuKey = 'sv_site_settings_v1';

    if ($apcu && !$forceRefresh) {
        $ok = false;
        $stored = apcu_fetch($apcuKey, $ok);
        if ($ok && is_array($stored)) {
            $cache = $stored;
            return $cache;
        }
    }

    $cache = [];
    $pdo = sv_pdo();
    if ($pdo) {
        try {
            $stmt = $pdo->query('SELECT setting_key, setting_value FROM site_settings');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $key = trim((string)($row['setting_key'] ?? ''));
                if ($key !== '') {
                    $cache[$key] = (string)($row['setting_value'] ?? '');
                }
            }
        } catch (Throwable $e) {
            // Tabela ainda inexistente (primeiro deploy): cria e tenta de novo.
            sv_settings_ensure_schema();
            try {
                $stmt = $pdo->query('SELECT setting_key, setting_value FROM site_settings');
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $key = trim((string)($row['setting_key'] ?? ''));
                    if ($key !== '') {
                        $cache[$key] = (string)($row['setting_value'] ?? '');
                    }
                }
            } catch (Throwable $e2) {
                error_log('[SiteSettings] bulk load failed: ' . $e2->getMessage());
            }
        }
    }

    if ($apcu) {
        apcu_store($apcuKey, $cache, 60);
    }

    return $cache;
}

function sv_setting_get(string $key, ?string $default = null): ?string
{
    $all = sv_settings_all();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

function sv_setting_set(string $key, string $value): void
{
    sv_settings_ensure_schema();
    $pdo = sv_pdo();
    if (!$pdo) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO site_settings (setting_key, setting_value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([':k' => $key, ':v' => $value]);

    // Invalida o cache imediatamente: o admin precisa ver o efeito na hora.
    if (function_exists('apcu_delete')) {
        apcu_delete('sv_site_settings_v1');
    }
    sv_settings_all(true);

    // O admin lê o valor de volta na mesma requisição (ex.: admin/
    // configuracoes-frete.php) — o memo precisa cair junto.
    $memo = &sv_free_shipping_memo();
    $memo = null;
}

/** Memo por requisição do frete grátis (resetável após escrita). */
function &sv_free_shipping_memo(): ?array
{
    static $config = null;
    return $config;
}

/** Frete gratis fica DESABILITADO por padrao ate ser configurado explicitamente no admin. */
function sv_free_shipping_config(): array
{
    $config = &sv_free_shipping_memo();
    if ($config !== null) {
        return $config;
    }

    $enabled = sv_setting_get('free_shipping_enabled', '0') === '1';
    $threshold = (float)(sv_setting_get('free_shipping_threshold', '0') ?? '0');
    $config = ['enabled' => $enabled, 'threshold' => $threshold];

    return $config;
}
