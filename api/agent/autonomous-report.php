<?php
declare(strict_types=1);

// Bootstrap .env for public diagnostics that depend on runtime secrets.
(static function (): void {
    $envFile = dirname(__DIR__, 2) . '/.env';
    if (!is_file($envFile)) {
        return;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), '"\'');
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_SERVER[$key] = $value;
        }
    }
})();

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function svar_root(): string { return dirname(__DIR__, 2); }

function svar_json(string $rel): array {
    $path = svar_root() . '/' . ltrim($rel, '/');
    if (!is_file($path)) return [];
    $d = json_decode((string)file_get_contents($path), true);
    return is_array($d) ? $d : [];
}

function svar_file_age(string $rel): ?int {
    $path = svar_root() . '/' . ltrim($rel, '/');
    return is_file($path) ? (int)(time() - filemtime($path)) : null;
}

function svar_env_present(string $key): bool {
    $value = getenv($key);
    return is_string($value) && trim($value) !== '';
}

function svar_any_env_present(array $keys): bool {
    foreach ($keys as $key) {
        if (svar_env_present($key)) return true;
    }
    return false;
}

$catalog    = svar_json('api/catalog/fallback-products.json');
$ranking    = svar_json('autodev/data/product_ranking.json');
$demand     = svar_json('autodev/data/demand_forecast.json');
$roiReport  = svar_json('logs/roi-engine-report.json');
$triSync    = svar_json('logs/tri-environment-sync.json');
$cycleLog   = svar_json('scripts/autonomous-cycle-log.json');
$emailReport = svar_root() . '/logs/email-activity-report.txt';
$automationEmailReport = svar_root() . '/logs/automation-email-report.txt';
$emailConfigCheck = svar_json('logs/email-config-check.json');
$systemHealth = svar_json('logs/system-health-check.json');
$deployDiagnostic = svar_json('logs/deploy-diagnostic.json');
$codexRoundsReport = svar_root() . '/logs/codex-50-rounds-report.md';
$checkoutOptimizerReport = svar_json('logs/checkout-optimizer-report.json');
if (empty($triSync)) {
    $triSync = svar_json('logs/autonomous-sync.json');
}

$salesCoreKeys = ['LOJA_PIX_KEY', 'LOJA_PIX_NAME', 'LOJA_WHATSAPP'];
$salesShippingKeys = ['MELHORENVIO_ACCESS_TOKEN', 'MELHORENVIO_API_KEY', 'MELHORENVIO_FROM_POSTAL_CODE'];
$salesPaymentKeys = ['PAGARME_SECRET_KEY', 'PAGARME_API_KEY', 'PAGARME_PUBLIC_KEY'];
$salesMarketplaceKeys = ['SHOPEE_PARTNER_ID', 'SHOPEE_PARTNER_KEY', 'SHOPEE_SHOP_ID', 'SHOPEE_REFRESH_TOKEN', 'ML_CLIENT_ID', 'ML_CLIENT_SECRET', 'ML_REDIRECT_URI'];
$salesMissingKeys = array_values(array_filter(array_merge($salesCoreKeys, $salesShippingKeys, $salesPaymentKeys, $salesMarketplaceKeys), fn($key) => !svar_env_present($key)));
$emailConfigured = svar_any_env_present(['SMTP_HOST', 'EMAIL_SMTP_HOST', 'MAIL_HOST'])
    && svar_any_env_present(['SMTP_USER', 'EMAIL_USER', 'MAIL_USER'])
    && svar_any_env_present(['SMTP_PASS', 'EMAIL_PASSWORD', 'MAIL_PASS'])
    && svar_env_present('EMAIL_TO');

$totalProducts = count($catalog);
$noImage = 0;
$zeroPrice = 0;
foreach ($catalog as $p) {
    if (!is_array($p)) continue;
    $img = trim((string)($p['image_url'] ?? ''));
    if ($img === '' || $img === '/favicon.ico') $noImage++;
    if ((float)($p['price'] ?? 0) <= 0) $zeroPrice++;
}

$mlTokenPath = svar_root() . '/storage/private/ml-tokens.json';
$mlTokenAge  = is_file($mlTokenPath) ? (int)(time() - filemtime($mlTokenPath)) : null;
$mlTokenData = is_file($mlTokenPath) ? json_decode((string)file_get_contents($mlTokenPath), true) : null;

echo json_encode([
    'agent'        => 'autonomous-report',
    'generated_at' => date('c'),
    'catalog' => [
        'total'       => $totalProducts,
        'no_image'    => $noImage,
        'zero_price'  => $zeroPrice,
        'file_age_s'  => svar_file_age('api/catalog/fallback-products.json'),
    ],
    'ranking' => [
        'available'   => !empty($ranking),
        'generated_at'=> $ranking['generated_at'] ?? null,
        'count'       => count($ranking['order'] ?? []),
        'file_age_s'  => svar_file_age('autodev/data/product_ranking.json'),
    ],
    'demand' => [
        'available'   => !empty($demand),
        'generated_at'=> $demand['generated_at'] ?? null,
        'count'       => count($demand['products'] ?? []),
        'file_age_s'  => svar_file_age('autodev/data/demand_forecast.json'),
    ],
    'roi' => [
        'available'        => !empty($roiReport),
        'generated_at'     => $roiReport['generated_at'] ?? null,
        'director_basis'   => $roiReport['director_basis'] ?? null,
        'products_loaded'  => $roiReport['summary']['products_loaded'] ?? null,
        'top_opportunities'=> array_slice($roiReport['top_opportunities'] ?? $roiReport['priorities'] ?? [], 0, 5),
        'task_recommendations' => array_slice($roiReport['task_recommendations'] ?? [], 0, 5),
    ],
    'sales_flow' => [
        'ready_now'        => empty(array_diff($salesCoreKeys, array_filter($salesCoreKeys, fn($key) => svar_env_present($key)))),
        'missing_credentials' => $salesMissingKeys,
        'focus'            => array_slice($roiReport['top_opportunities'] ?? $roiReport['priorities'] ?? [], 0, 3),
    ],
    'integrations' => [
        'ml_oauth_connected' => is_array($mlTokenData) && !empty($mlTokenData['access_token']),
        'ml_token_age_s'     => $mlTokenAge,
        'ml_user_id'         => is_array($mlTokenData) ? ($mlTokenData['user_id'] ?? null) : null,
        'shopee_repair_done' => is_file(svar_root() . '/utils/shopee_client.py'),
        'git_guardian_active'=> is_file(svar_root() . '/scripts/git_autonomous_agent.py'),
    ],
    'tri_environment_sync' => [
        'available'        => !empty($triSync),
        'generated_at'     => $triSync['generated_at'] ?? null,
        'environment'      => $triSync['environment'] ?? null,
        'status'           => $triSync['status'] ?? null,
        'next_action'      => $triSync['nextAction'] ?? null,
        'branch'           => $triSync['git']['branch'] ?? null,
        'head'             => $triSync['git']['head'] ?? null,
        'remote_head'      => $triSync['git']['remote_head'] ?? null,
        'ahead_by'         => $triSync['git']['ahead_by'] ?? null,
        'behind_by'        => $triSync['git']['behind_by'] ?? null,
        'dirty_count'      => $triSync['git']['dirty_count'] ?? null,
        'actions'          => array_slice($triSync['actions'] ?? [], 0, 5),
        'warnings'         => array_slice($triSync['warnings'] ?? [], 0, 5),
        'file_age_s'       => svar_file_age('logs/tri-environment-sync.json'),
        'legacy_file_age_s' => svar_file_age('logs/autonomous-sync.json'),
    ],
    'email_report' => [
        'available' => is_file($emailReport),
        'file_age_s' => svar_file_age('logs/email-activity-report.txt'),
        'automation_report_available' => is_file($automationEmailReport),
        'automation_report_file_age_s' => svar_file_age('logs/automation-email-report.txt'),
        'config_check_available' => !empty($emailConfigCheck),
        'config_check_ok' => $emailConfigCheck['ok'] ?? null,
        'smtp_configured' => $emailConfigured,
        'recipients_configured' => svar_env_present('EMAIL_TO'),
    ],
    'system_health' => [
        'available' => !empty($systemHealth),
        'status' => $systemHealth['status'] ?? null,
        'errors' => count($systemHealth['errors'] ?? []),
        'warnings' => count($systemHealth['warnings'] ?? []),
        'file_age_s' => svar_file_age('logs/system-health-check.json'),
    ],
    'autonomous_cycle' => [
        'available' => !empty($cycleLog),
        'status' => $cycleLog['status'] ?? null,
        'mode' => $cycleLog['mode'] ?? null,
        'last_cycle_at' => $cycleLog['last_cycle_at'] ?? null,
        'selection_reason' => $cycleLog['selection_reason'] ?? null,
        'file_age_s' => svar_file_age('scripts/autonomous-cycle-log.json'),
    ],
    'deploy_diagnostic' => [
        'available' => !empty($deployDiagnostic),
        'ok' => $deployDiagnostic['ok'] ?? null,
        'issues' => array_slice($deployDiagnostic['issues'] ?? [], 0, 5),
        'warnings' => array_slice($deployDiagnostic['warnings'] ?? [], 0, 5),
        'file_age_s' => svar_file_age('logs/deploy-diagnostic.json'),
    ],
    'codex_rounds' => [
        'available' => is_file($codexRoundsReport),
        'file_age_s' => svar_file_age('logs/codex-50-rounds-report.md'),
    ],
    'checkout_optimizer' => [
        'available' => !empty($checkoutOptimizerReport),
        'generated_at' => $checkoutOptimizerReport['generated_at'] ?? null,
        'checkout_ready' => $checkoutOptimizerReport['checkout_ready'] ?? null,
        'critical_issues' => array_slice($checkoutOptimizerReport['signals']['critical_issues'] ?? [], 0, 5),
        'warnings' => array_slice($checkoutOptimizerReport['signals']['warnings'] ?? [], 0, 5),
    ],
    'endpoints' => [
        'autonomous_watchdog' => is_file(svar_root() . '/api/agent/autonomous-watchdog.php'),
        'external_trigger'    => is_file(svar_root() . '/api/agent/external-trigger.php'),
        'squad_chat'          => is_file(svar_root() . '/api/agent/squad-chat.php'),
        'ml_login'            => is_file(svar_root() . '/api/ml/login.php'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
