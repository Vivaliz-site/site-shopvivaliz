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
        && str_contains($api, 'hash_equals($account, $run[\'account\'])'),
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
    'official active-only filter requires status from backend source' =>
        str_contains($page, 'only-active')
        && str_contains($page, "l.status==='active'")
        && str_contains($page, "activeCount===0")
        && str_contains($page, "mlStatusText(listing.status)"),
    'active-only audit explicitly submits only vetted IDs never backend ALL' =>
        str_contains($page, "scope='SELECTED';")
        && str_contains($page, "ids=scope==='ALL'?[...activeIds]:ids.filter(id=>activeIds.has(id))")
        && str_contains($page, "if(!ids.length){feedback("),
    'active selection drops items no longer active after refresh' =>
        str_contains($page, "&&(!state.activeOnly||l.status==='active')")
        && str_contains($page, "state.selected=new Set([...state.selected].filter(id=>allowed.has(id)))"),
    'backend selection conflict is never silently interpreted as a successful run' =>
        str_contains($page, 'AUDIT_ALREADY_RUNNING_DIFFERENT_SELECTION')
        && str_contains($page, 'outra auditoria em execução')
        && str_contains($page, 'Aguarde a conclusão')
        && str_contains($page, "if(!response.ok){"),
    'incomplete audit rows expose real price cost tax fee and shipping gaps' =>
        str_contains($page, 'missing_inputs_json')
        && str_contains($page, 'MARKETPLACE_PRICE_NOT_SYNCED')
        && str_contains($page, 'MISSING_COST')
        && str_contains($page, 'MISSING_PRODUCT_COST')
        && str_contains($page, 'MISSING_PACKAGING_COST')
        && str_contains($page, 'MISSING_OTHER_VARIABLE_COST')
        && str_contains($page, 'hasDetailedCost')
        && str_contains($page, 'MISSING_TAX_PROFILE')
        && str_contains($page, 'MISSING_FEE_QUOTE')
        && str_contains($page, 'MISSING_SHIPPING_QUOTE')
        && str_contains($page, 'Motivos inválidos no relatório')
        && str_contains($page, 'function diagnostic(row)')
        && str_contains($page, 'td.textContent='),
    'price simulation uses only the existing quote endpoint and server account binding' =>
        str_contains($api, "\$writeActions = ['start', 'resume', 'preview'];")
        && str_contains($api, "'/v1/pricing/quote'")
        && str_contains($api, "'requested_price' => \$payload['requested_price']")
        && str_contains($api, "'account_id' => \$account")
        && str_contains($api, "'quantity' => 1")
        && str_contains($api, "array_diff(array_keys(\$payload), ['item_id', 'requested_price'])")
        && str_contains($page, "request('preview',{method:'POST'")
        && str_contains($page, "quote.sources?.price?.origin!=='REQUESTED_PRICE'"),
    'price preview refuses older backend before posting what-if financial data' =>
        str_contains($api, "svmlm_require_nonpersistent_preview(\$token);")
        && str_contains($api, "svmlm_upstream('/v1/health', 'GET', null, \$token)")
        && str_contains($api, "'pricing_requested_price_preview'")
        && str_contains($api, "'simulated-no-" . "ledger-v1'")
        && str_contains($api, "PREVIEW_BACKEND_UPGRADE_REQUIRED")
        && str_contains($api, "PREVIEW_BACKEND_CONTRACT_INVALID")
        && str_contains($api, "(\$preview['state'] ?? null) !== 'SIMULATED'")
        && str_contains($api, "(\$preview['persisted'] ?? null) !== false")
        && str_contains($api, "\$preview['snapshot_id'] !== null")
        && str_contains($page, "quote.state!=='SIMULATED'")
        && str_contains($page, 'quote.persisted!==false')
        && str_contains($page, 'quote.snapshot_id!==null')
        && !str_contains($page, "quote.state!=='ESTIMATED'"),
    'simulation requires active and variant-free listing and never publishes prices' =>
        str_contains($page, "simulationListing.status==='active'")
        && str_contains($page, "simulationListing.variants||[]")
        && str_contains($page, "Nenhuma alteração é enviada ao Mercado Livre ou ao ERP.")
        && str_contains($page, "SIMULAÇÃO: preço NÃO alterado")
        && !str_contains($api, "'/items/")
        && !str_contains($api, "'/produtos/")
        && !str_contains($api, "'/v1/pricing/price-update"),
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
