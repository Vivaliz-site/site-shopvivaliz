<?php
declare(strict_types=1);

/**
 * Static security contract for the new admin gateway/dashboard.
 * Also run php -l and real UI E2E with a granted account before production acceptance.
 */
$root = dirname(__DIR__, 2);
$api = (string) file_get_contents($root . '/admin/mercadolivre-margin-api.php');
$page = (string) file_get_contents($root . '/admin/mercadolivre-margin.php');
$parent = (string) file_get_contents($root . '/admin/mercadolivre.php');

$checks = [
    'both endpoints use the admin session guard' =>
        str_contains($api, "includes/admin-guard.php") && str_contains($page, "includes/admin-guard.php"),
    'backend URL is fixed and HTTPS verified' =>
        str_contains($api, 'https://pricing.shopvivaliz.com.br')
        && str_contains($api, 'CURLOPT_SSL_VERIFYPEER => true')
        && str_contains($api, 'CURLOPT_FOLLOWLOCATION => false'),
    'browser never references API token settings' =>
        !str_contains($page, 'ML_PRICING_AUDIT_API_TOKEN')
        && !str_contains($page, 'Bearer '),
    'server never returns credential values in an error' =>
        str_contains($api, "'error' => ['code' =>")
        && str_contains($api, 'BACKEND_HTTP_'),
    'POST requires session CSRF token' =>
        str_contains($api, 'hash_equals($expected, $submitted)')
        && str_contains($page, "'X-CSRF-Token':csrf"),
    'run ID is bound to both the session and server-side account' =>
        str_contains($api, "sv_ml_margin_last_run")
        && str_contains($api, "hash_equals($account, $run['account'])"),
    'backend enqueues one item per Mercado Livre ID' =>
        str_contains($api, "'item_ids'")
        && str_contains($page, 'state.selected'),
    'reports are only downloadable in completed states' =>
        str_contains($page, 'completeStatuses.has(state.status.status)')
        && str_contains($api, "['pdf', 'xlsx']"),
    'no unsafe HTML interpolation in dynamic table rows' =>
        str_contains($page, 'td.textContent=')
        && !str_contains($page, '.innerHTML='),
    'no catalog or marketplace price mutation route exists in proxy' =>
        !str_contains($api, '/items/MLB')
        && !str_contains($api, '/produtos/')
        && !str_contains($api, 'CURLOPT_CUSTOMREQUEST => "PUT"'),
    'dashboard linked from the existing Mercado Livre admin' =>
        str_contains($parent, '/admin/mercadolivre-margin.php'),
];

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "[FAIL] " . $label . PHP_EOL);
        exit(1);
    }
}
echo "[PASS] " . count($checks) . " ML margin admin security/UI source contracts" . PHP_EOL;
