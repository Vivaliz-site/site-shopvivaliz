#!/usr/bin/env python3
from __future__ import annotations

from pathlib import Path
import sys

ROOT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path.cwd()


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def write(path: str, content: str) -> None:
    (ROOT / path).write_text(content, encoding="utf-8", newline="\n")


def replace_once(path: str, old: str, new: str) -> None:
    text = read(path)
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f"{path}: expected exactly one match, found {count}: {old[:120]!r}")
    write(path, text.replace(old, new, 1))


# 1) PDO: establishing the PDO connection already validates connectivity. Keep
# the health probe only for a cached connection that may die during a long request.
replace_once(
    "includes/pdo-database.php",
    " * 2s provocava falsos \"banco desconectado\" no Admin. Usamos 5s, alinhado ao\n * mysqli canônico, e validamos a sessão com SELECT 1 antes de devolvê-la.\n",
    " * 2s provocava falsos \"banco desconectado\" no Admin. Usamos 5s, alinhado ao\n * mysqli canônico. A própria criação do PDO já valida a conexão; evitamos um\n * SELECT 1 redundante no caminho de abertura.\n",
)
replace_once(
    "includes/pdo-database.php",
    "        ]);\n        $pdo->query('SELECT 1')->fetchColumn();\n        return $pdo;\n",
    "        ]);\n        return $pdo;\n",
)

# 2) Site settings: one SELECT per request at most, plus short APCu cache when
# available. Writes invalidate/update both request and APCu caches.
write(
    "includes/site-settings.php",
    r'''<?php
declare(strict_types=1);

require_once __DIR__ . '/pdo-database.php';

const SV_SITE_SETTINGS_CACHE_KEY = 'shopvivaliz:site_settings:v2';
const SV_SITE_SETTINGS_SCHEMA_CACHE_KEY = 'shopvivaliz:site_settings_schema:v1';

function sv_settings_apcu_enabled(): bool
{
    if (!function_exists('apcu_fetch') || !function_exists('apcu_store')) {
        return false;
    }
    return !function_exists('apcu_enabled') || apcu_enabled();
}

function sv_settings_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (sv_settings_apcu_enabled() && apcu_exists(SV_SITE_SETTINGS_SCHEMA_CACHE_KEY)) {
        return;
    }

    $pdo = sv_pdo();
    if (!$pdo) {
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    if (sv_settings_apcu_enabled()) {
        @apcu_store(SV_SITE_SETTINGS_SCHEMA_CACHE_KEY, 1, 3600);
    }
}

/** @return array<string,string> */
function sv_settings_all(): array
{
    if (isset($GLOBALS['sv_site_settings_request_cache']) && is_array($GLOBALS['sv_site_settings_request_cache'])) {
        return $GLOBALS['sv_site_settings_request_cache'];
    }

    if (sv_settings_apcu_enabled()) {
        $success = false;
        $cached = apcu_fetch(SV_SITE_SETTINGS_CACHE_KEY, $success);
        if ($success && is_array($cached)) {
            $GLOBALS['sv_site_settings_request_cache'] = $cached;
            return $cached;
        }
    }

    sv_settings_ensure_schema();
    try {
        $pdo = sv_pdo();
        if (!$pdo) {
            return [];
        }
        $stmt = $pdo->query('SELECT setting_key, setting_value FROM site_settings');
        $settings = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = trim((string)($row['setting_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $settings[$key] = (string)($row['setting_value'] ?? '');
        }
        $GLOBALS['sv_site_settings_request_cache'] = $settings;
        if (sv_settings_apcu_enabled()) {
            @apcu_store(SV_SITE_SETTINGS_CACHE_KEY, $settings, 60);
        }
        return $settings;
    } catch (Throwable $e) {
        error_log('[SiteSettings] load failed: ' . $e->getMessage());
        return [];
    }
}

function sv_setting_get(string $key, ?string $default = null): ?string
{
    $settings = sv_settings_all();
    return array_key_exists($key, $settings) ? (string)$settings[$key] : $default;
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

    if (isset($GLOBALS['sv_site_settings_request_cache']) && is_array($GLOBALS['sv_site_settings_request_cache'])) {
        $GLOBALS['sv_site_settings_request_cache'][$key] = $value;
        if (sv_settings_apcu_enabled()) {
            @apcu_store(SV_SITE_SETTINGS_CACHE_KEY, $GLOBALS['sv_site_settings_request_cache'], 60);
        }
    } elseif (sv_settings_apcu_enabled()) {
        @apcu_delete(SV_SITE_SETTINGS_CACHE_KEY);
    }
}

/** Frete gratis fica DESABILITADO por padrao ate ser configurado explicitamente no admin. */
function sv_free_shipping_config(): array
{
    $settings = sv_settings_all();
    $enabled = (string)($settings['free_shipping_enabled'] ?? '0') === '1';
    $threshold = (float)($settings['free_shipping_threshold'] ?? '0');
    return ['enabled' => $enabled, 'threshold' => $threshold];
}
''',
)

# 3) Shared webhook-secret helper. Deliberately only uses OLIST_WEBHOOK_SECRET:
# deployment remains backwards compatible/permissive until the new secret is
# provisioned and provider URLs are updated together.
write(
    "includes/webhook-secret.php",
    r'''<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap-env.php';

function sv_webhook_shared_secret(): string
{
    $value = getenv('OLIST_WEBHOOK_SECRET');
    return is_string($value) ? trim($value) : '';
}

function sv_webhook_request_secret(): string
{
    $query = trim((string)($_GET['key'] ?? ''));
    if ($query !== '') {
        return $query;
    }

    foreach (['HTTP_X_WEBHOOK_SECRET', 'HTTP_X_OLIST_WEBHOOK_SECRET'] as $serverKey) {
        $value = trim((string)($_SERVER[$serverKey] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($authorization === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp((string)$name, 'Authorization') === 0) {
                $authorization = trim((string)$value);
                break;
            }
            if (in_array(strtolower((string)$name), ['x-webhook-secret', 'x-olist-webhook-secret'], true)) {
                $headerValue = trim((string)$value);
                if ($headerValue !== '') {
                    return $headerValue;
                }
            }
        }
    }
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match)) {
        return trim((string)$match[1]);
    }

    return '';
}

/** @return array{configured:bool,valid:bool,provided:bool} */
function sv_webhook_secret_status(): array
{
    $expected = sv_webhook_shared_secret();
    $provided = sv_webhook_request_secret();
    return [
        'configured' => $expected !== '',
        'valid' => $expected !== '' && $provided !== '' && hash_equals($expected, $provided),
        'provided' => $provided !== '',
    ];
}
''',
)

# 4) Mercado Pago queue workers may only process jobs authenticated at HTTP edge.
replace_once(
    "includes/webhook-job-dispatcher.php",
    "function sv_webhook_job_dispatch_mercadopago(array $payload): array\n{\n    $dataId = trim((string)($payload['data_id'] ?? ''));\n",
    "function sv_webhook_job_dispatch_mercadopago(array $payload): array\n{\n    if (($payload['auth_validated'] ?? false) !== true) {\n        return ['success' => false, 'error' => 'auth_not_validated'];\n    }\n\n    $dataId = trim((string)($payload['data_id'] ?? ''));\n",
)

# 5) Secure cookies when TLS terminates at a trusted reverse proxy.
replace_once(
    "includes/secure-session.php",
    "    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443;\n",
    "    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));\n    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')\n        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443\n        || $forwardedProto === 'https';\n",
)

# 6) Admin settings: require authenticated admin + CSRF on writes.
replace_once(
    "admin/settings.php",
    "require_once __DIR__ . '/../config/bootstrap-env.php';\nrequire_once __DIR__ . '/../includes/site-settings.php';\n",
    "require_once __DIR__ . '/../config/bootstrap-env.php';\nrequire_once __DIR__ . '/../includes/admin-guard.php';\nrequire_once __DIR__ . '/../includes/csrf.php';\nrequire_once __DIR__ . '/../includes/site-settings.php';\n",
)
replace_once(
    "admin/settings.php",
    "if ($_SERVER['REQUEST_METHOD'] === 'POST') {\n    try {\n        foreach ($fields as $key => $label) {\n            $value = trim((string)($_POST[$key] ?? ''));\n            sv_setting_set('social_' . $key, $value);\n        }\n        $message = 'Configurações salvas com sucesso.';\n    } catch (Throwable $e) {\n        error_log('[AdminSettings] save failed: ' . $e->getMessage());\n        $error = 'Não foi possível salvar as configurações agora.';\n    }\n}\n",
    "if ($_SERVER['REQUEST_METHOD'] === 'POST') {\n    if (!sv_csrf_valid('admin-settings', $_POST['csrf_token'] ?? null)) {\n        http_response_code(403);\n        $error = 'Sessão de formulário expirada. Recarregue a página e tente novamente.';\n    } else {\n        try {\n            foreach ($fields as $key => $label) {\n                $value = trim((string)($_POST[$key] ?? ''));\n                sv_setting_set('social_' . $key, $value);\n            }\n            $message = 'Configurações salvas com sucesso.';\n        } catch (Throwable $e) {\n            error_log('[AdminSettings] save failed: ' . $e->getMessage());\n            $error = 'Não foi possível salvar as configurações agora.';\n        }\n    }\n}\n",
)
replace_once(
    "admin/settings.php",
    "        <form method=\"post\" action=\"\">\n",
    "        <form method=\"post\" action=\"\">\n            <?= sv_csrf_input('admin-settings') ?>\n",
)

# 7) Shipping settings: same guard and CSRF protection.
replace_once(
    "admin/settings-frete.php",
    "require_once __DIR__ . '/../config/bootstrap-env.php';\nrequire_once __DIR__ . '/../includes/site-settings.php';\n\n// POST: Salvar configurações\nif ($_SERVER['REQUEST_METHOD'] === 'POST') {\n    $enabled = isset($_POST['free_shipping_enabled']) ? '1' : '0';\n    $threshold = (float)($_POST['free_shipping_threshold'] ?? '0');\n\n    if ($threshold < 0) $threshold = 0;\n\n    sv_setting_set('free_shipping_enabled', $enabled);\n    sv_setting_set('free_shipping_threshold', (string)$threshold);\n\n    $message = '✅ Configurações atualizadas com sucesso!';\n}\n",
    "require_once __DIR__ . '/../config/bootstrap-env.php';\nrequire_once __DIR__ . '/../includes/admin-guard.php';\nrequire_once __DIR__ . '/../includes/csrf.php';\nrequire_once __DIR__ . '/../includes/site-settings.php';\n\n$message = '';\n$error = '';\n\n// POST: Salvar configurações\nif ($_SERVER['REQUEST_METHOD'] === 'POST') {\n    if (!sv_csrf_valid('admin-settings-frete', $_POST['csrf_token'] ?? null)) {\n        http_response_code(403);\n        $error = 'Sessão de formulário expirada. Recarregue a página e tente novamente.';\n    } else {\n        $enabled = isset($_POST['free_shipping_enabled']) ? '1' : '0';\n        $threshold = (float)($_POST['free_shipping_threshold'] ?? '0');\n\n        if ($threshold < 0) $threshold = 0;\n\n        sv_setting_set('free_shipping_enabled', $enabled);\n        sv_setting_set('free_shipping_threshold', (string)$threshold);\n\n        $message = '✅ Configurações atualizadas com sucesso!';\n    }\n}\n",
)
replace_once(
    "admin/settings-frete.php",
    "        <?php if (isset($message)): ?>\n            <div class=\"message\"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>\n        <?php endif; ?>\n",
    "        <?php if ($message !== ''): ?>\n            <div class=\"message\"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>\n        <?php endif; ?>\n        <?php if ($error !== ''): ?>\n            <div class=\"message\" style=\"background:#fee2e2;color:#991b1b;border-color:#fecaca\"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>\n        <?php endif; ?>\n",
)
replace_once(
    "admin/settings-frete.php",
    "        <form method=\"POST\">\n",
    "        <form method=\"POST\">\n            <?= sv_csrf_input('admin-settings-frete') ?>\n",
)

# 8) Public Apache hardening. These rules run before the real-file bypass.
replace_once(
    ".htaccess",
    "    RewriteRule ^logs/ - [F,L]\n",
    "    RewriteRule ^logs/ - [F,L]\n    RewriteRule ^api/catalog/fallback-products\\.json$ - [F,L,NC]\n    RewriteRule ^api/autonomous(?:/|$) - [F,L,NC]\n    RewriteRule ^admin/(?:[^/]+/)*src(?:/|$) - [F,L,NC]\n",
)

# 9) Mercado Pago: validate signature before enqueue; mark authenticated jobs.
replace_once(
    "api/webhook-mercadopago.php",
    """$queuedId = sv_webhook_enqueue('mercadopago', [
    'raw' => $raw,
    'data_id' => $dataId,
    'topic' => $topic,
    'signature' => $signature,
    'request_id' => $requestId,
    'received_at' => date(DATE_ATOM),
]);
error_log('[MercadoPago] webhook queued id=' . $queuedId . ' data=' . $dataId);
svmp_webhook_response(200, 'queued');
if (!svmp_validate_webhook_signature($signature, $requestId, $dataId, $webhookSecret)) {
    error_log('[MercadoPago] webhook rejected: invalid signature request=' . substr($requestId, 0, 80) . ' sig_len=' . strlen($signature) . ' data_id=' . substr($dataId, 0, 80));
    svmp_webhook_response(401, 'invalid_signature');
}
""",
    """if (!svmp_validate_webhook_signature($signature, $requestId, $dataId, $webhookSecret)) {
    error_log('[MercadoPago] webhook rejected: invalid signature request=' . substr($requestId, 0, 80) . ' sig_len=' . strlen($signature) . ' data_id=' . substr($dataId, 0, 80));
    svmp_webhook_response(401, 'invalid_signature');
}

$queuedId = sv_webhook_enqueue('mercadopago', [
    'raw' => $raw,
    'data_id' => $dataId,
    'topic' => $topic,
    'signature' => $signature,
    'request_id' => $requestId,
    'received_at' => date(DATE_ATOM),
    'auth_validated' => true,
]);
error_log('[MercadoPago] webhook queued id=' . $queuedId . ' data=' . $dataId);
svmp_webhook_response(200, 'queued');
""",
)

# 10) Olist webhook: optional-until-provisioned shared key; destructive delete is
# fail-closed even during the migration window. Processor owns final response.
write(
    "api/olist/webhook.php",
    r'''<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Webhook-Secret, X-Olist-Webhook-Secret');

require_once dirname(__DIR__, 2) . '/includes/webhook-secret.php';

function svoh_reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    svoh_reply(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 262144) {
    svoh_reply(413, ['ok' => false, 'error' => 'payload_too_large']);
}
$body = json_decode($raw, true);
if (!is_array($body)) {
    svoh_reply(400, ['ok' => false, 'error' => 'invalid_json']);
}
$eventType = trim((string)($body['event'] ?? $body['type'] ?? ''));
$auth = sv_webhook_secret_status();
if ($auth['configured'] && !$auth['valid']) {
    svoh_reply(401, ['ok' => false, 'error' => 'unauthorized']);
}
if (!$auth['configured']) {
    if ($eventType === 'product.deleted') {
        error_log('[OlistWebhook] destructive event rejected while OLIST_WEBHOOK_SECRET is unconfigured');
        svoh_reply(503, ['ok' => false, 'error' => 'webhook_secret_required']);
    }
    error_log('[OlistWebhook] OLIST_WEBHOOK_SECRET is not configured; accepting non-destructive event in migration mode');
}

$eventId = 'olist:' . hash('sha256', $raw !== '' ? $raw : json_encode($body));
header('X-ShopVivaliz-Webhook-Event: ' . substr($eventId, 0, 72));

require __DIR__ . '/webhook-processor.php';
exit;
''',
)

# 11) Tiny stock webhook: same migration-safe key plus basic payload/stock sanity.
write(
    "api/tiny/stock-webhook.php",
    r'''<?php
declare(strict_types=1);

/**
 * Webhook de atualizacao de estoque da Tiny/Olist (API 2.0).
 * Quando OLIST_WEBHOOK_SECRET estiver configurado, a URL deve incluir ?key=...
 * (ou enviar X-Webhook-Secret/Authorization Bearer). Sem o segredo configurado,
 * eventos de estoque permanecem temporariamente permissivos para permitir uma
 * migracao sem interromper sincronizacao.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/includes/webhook-secret.php';

function svtw_log(string $message): void
{
    $logFile = dirname(__DIR__, 2) . '/logs/tiny-stock-webhook.log';
    @mkdir(dirname($logFile), 0755, true);
    @file_put_contents($logFile, '[' . date('c') . '] ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

function svtw_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    svtw_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$auth = sv_webhook_secret_status();
if ($auth['configured'] && !$auth['valid']) {
    svtw_json(401, ['ok' => false, 'error' => 'unauthorized']);
}
if (!$auth['configured']) {
    error_log('[TinyStockWebhook] OLIST_WEBHOOK_SECRET is not configured; accepting stock event in migration mode');
}

$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 131072) {
    svtw_json(413, ['ok' => false, 'error' => 'payload_too_large']);
}
$body = json_decode($raw, true);

if (!is_array($body) || ($body['tipo'] ?? '') !== 'estoque') {
    svtw_log('Payload ignorado (tipo != estoque): ' . substr($raw, 0, 300));
    svtw_json(200, ['ok' => true, 'ignored' => true]);
}

$dados = is_array($body['dados'] ?? null) ? $body['dados'] : [];
$sku = trim((string)($dados['skuMapeamento'] ?? $dados['sku'] ?? ''));
$saldo = $dados['saldo'] ?? null;

if ($sku === '' || $saldo === null) {
    svtw_log('Payload sem sku/saldo: ' . substr($raw, 0, 300));
    svtw_json(200, ['ok' => true, 'ignored' => true, 'reason' => 'missing_sku_or_saldo']);
}
if (!is_numeric($saldo) || !is_finite((float)$saldo) || abs((float)$saldo) > 1000000) {
    svtw_log('Saldo fora do limite de sanidade para sku=' . substr($sku, 0, 120));
    svtw_json(400, ['ok' => false, 'error' => 'invalid_stock_value']);
}

$stock = (int)round((float)$saldo);
$catalogPath = dirname(__DIR__, 2) . '/api/catalog/fallback-products.json';

$fp = fopen($catalogPath, 'c+');
if (!$fp) {
    svtw_log("Falha ao abrir {$catalogPath}");
    svtw_json(500, ['ok' => false, 'error' => 'catalog_unavailable']);
}

flock($fp, LOCK_EX);
$content = stream_get_contents($fp);
$catalog = json_decode($content !== false ? $content : '[]', true);
if (!is_array($catalog)) {
    $catalog = [];
}

$updated = false;
foreach ($catalog as &$product) {
    if (!is_array($product)) {
        continue;
    }
    if (strcasecmp((string)($product['sku'] ?? ''), $sku) === 0) {
        $product['stock'] = $stock;
        $updated = true;
        break;
    }
}
unset($product);

if ($updated) {
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
flock($fp, LOCK_UN);
fclose($fp);

svtw_log(($updated ? 'Atualizado' : 'SKU nao encontrado no catalogo') . ": sku={$sku} stock={$stock}");
svtw_json(200, ['ok' => true, 'updated' => $updated, 'sku' => $sku, 'stock' => $stock]);
''',
)

# 12) Mercado Livre: resource is attacker controlled. Never let a crafted
# authority/userinfo fragment change the host used by ml_http_get().
replace_once(
    "api/ml/webhook.php",
    """if ($entry['topic'] === 'orders_v2' && $entry['resource'] !== '') {
    try {
        ml_sync_order_from_webhook($entry['resource']);
    } catch (Throwable $e) {
        file_put_contents(
            $logDir . '/ml-webhook-errors.log',
            json_encode(['at' => gmdate('c'), 'resource' => $entry['resource'], 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
""",
    """if ($entry['topic'] === 'orders_v2' && $entry['resource'] !== '') {
    if (!preg_match('~^/orders/[0-9]+$~D', $entry['resource'])) {
        file_put_contents(
            $logDir . '/ml-webhook-errors.log',
            json_encode(['at' => gmdate('c'), 'resource' => substr($entry['resource'], 0, 180), 'error' => 'invalid_resource_path'], JSON_UNESCAPED_UNICODE) . "\\n",
            FILE_APPEND | LOCK_EX
        );
    } else {
        try {
            ml_sync_order_from_webhook($entry['resource']);
        } catch (Throwable $e) {
            file_put_contents(
                $logDir . '/ml-webhook-errors.log',
                json_encode(['at' => gmdate('c'), 'resource' => $entry['resource'], 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\\n",
                FILE_APPEND | LOCK_EX
            );
        }
    }
}
""",
)

# 13-14) Rate-limit public Liz endpoints before expensive provider/internal calls.
lizg_rate = r'''
function lizg_rate_limit(): void
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $dir = dirname(__DIR__) . '/storage/liz-rate-limit';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return;
    $file = $dir . '/general-' . hash('sha256', $ip) . '.json';
    $fp = @fopen($file, 'c+');
    if ($fp === false || !flock($fp, LOCK_EX)) { if (is_resource($fp)) fclose($fp); return; }
    rewind($fp);
    $stored = json_decode((string)stream_get_contents($fp), true);
    $stored = is_array($stored) ? $stored : [];
    $now = time();
    $reset = (int)($stored['reset'] ?? 0);
    $count = $reset > $now ? (int)($stored['count'] ?? 0) + 1 : 1;
    $reset = $reset > $now ? $reset : $now + 60;
    rewind($fp); ftruncate($fp, 0); fwrite($fp, json_encode(['count' => $count, 'reset' => $reset])); fflush($fp);
    flock($fp, LOCK_UN); fclose($fp);
    if ($count > 30) {
        header('Retry-After: ' . max(1, $reset - $now));
        lizg_reply(429, ['ok' => false, 'error' => 'Muitas solicitações. Aguarde alguns instantes e tente novamente.']);
    }
}
'''
replace_once(
    "api/liz-general.php",
    "function lizg_load_env(string $path): void\n",
    lizg_rate + "\nfunction lizg_load_env(string $path): void\n",
)
replace_once(
    "api/liz-general.php",
    "if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {\n    lizg_reply(405, ['ok' => false, 'error' => 'Método não permitido.']);\n}\n\n$input = json_decode",
    "if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {\n    lizg_reply(405, ['ok' => false, 'error' => 'Método não permitido.']);\n}\n\nlizg_rate_limit();\n\n$input = json_decode",
)

lizk_rate = r'''
function lizk_rate_limit(): void
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $dir = dirname(__DIR__) . '/storage/liz-rate-limit';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return;
    $file = $dir . '/knowledge-' . hash('sha256', $ip) . '.json';
    $fp = @fopen($file, 'c+');
    if ($fp === false || !flock($fp, LOCK_EX)) { if (is_resource($fp)) fclose($fp); return; }
    rewind($fp);
    $stored = json_decode((string)stream_get_contents($fp), true);
    $stored = is_array($stored) ? $stored : [];
    $now = time();
    $reset = (int)($stored['reset'] ?? 0);
    $count = $reset > $now ? (int)($stored['count'] ?? 0) + 1 : 1;
    $reset = $reset > $now ? $reset : $now + 60;
    rewind($fp); ftruncate($fp, 0); fwrite($fp, json_encode(['count' => $count, 'reset' => $reset])); fflush($fp);
    flock($fp, LOCK_UN); fclose($fp);
    if ($count > 30) {
        header('Retry-After: ' . max(1, $reset - $now));
        lizk_json_response(429, ['ok' => false, 'error' => 'Muitas solicitações. Aguarde alguns instantes e tente novamente.']);
    }
}
'''
replace_once(
    "api/liz-intelligent-knowledge.php",
    "$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';\n",
    lizk_rate + "\n$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';\n",
)
replace_once(
    "api/liz-intelligent-knowledge.php",
    "if ($method !== 'POST') {\n    lizk_json_response(405, ['ok' => false, 'error' => 'Metodo nao permitido.']);\n}\n\n$rawInput = file_get_contents",
    "if ($method !== 'POST') {\n    lizk_json_response(405, ['ok' => false, 'error' => 'Metodo nao permitido.']);\n}\n\nlizk_rate_limit();\n\n$rawInput = file_get_contents",
)

# 15) Home: warm the actual S3 origin and don't block parsing on legacy carousel.
replace_once(
    "index.php",
    "    <link rel=\"preconnect\" href=\"https://images.unsplash.com\" crossorigin>\n    <link rel=\"dns-prefetch\" href=\"https://images.unsplash.com\">\n",
    "    <link rel=\"preconnect\" href=\"https://images.unsplash.com\" crossorigin>\n    <link rel=\"dns-prefetch\" href=\"https://images.unsplash.com\">\n    <link rel=\"preconnect\" href=\"https://s3.amazonaws.com\" crossorigin>\n    <link rel=\"dns-prefetch\" href=\"https://s3.amazonaws.com\">\n",
)
replace_once(
    "index.php",
    "    <script src=\"/js/auto-image-carousel.js?v=20260811-1\"></script>\n",
    "    <script src=\"/js/auto-image-carousel.js?v=20260811-1\" defer></script>\n",
)

# 16) Admin dashboard XSS: construct list nodes and assign textContent only.
replace_once(
    "js/admin-dashboard.js",
    """  function setList(id, items) {
    const node = document.getElementById(id);
    if (!node) return;
    node.innerHTML = items.map(function (item) { return '<li>' + item + '</li>'; }).join('');
  }
""",
    """  function setList(id, items) {
    const node = document.getElementById(id);
    if (!node) return;
    node.replaceChildren();
    items.forEach(function (item) {
      const li = document.createElement('li');
      li.textContent = String(item);
      node.appendChild(li);
    });
  }
""",
)

expected = {
    '.htaccess',
    'admin/settings.php',
    'admin/settings-frete.php',
    'api/liz-general.php',
    'api/liz-intelligent-knowledge.php',
    'api/ml/webhook.php',
    'api/olist/webhook.php',
    'api/tiny/stock-webhook.php',
    'api/webhook-mercadopago.php',
    'includes/pdo-database.php',
    'includes/secure-session.php',
    'includes/site-settings.php',
    'includes/webhook-job-dispatcher.php',
    'includes/webhook-secret.php',
    'index.php',
    'js/admin-dashboard.js',
}
print("reconstructed audit changes for", len(expected), "files")
for path in sorted(expected):
    print(path)
