<?php
declare(strict_types=1);

function stock_contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$fetch = (string)file_get_contents($root . '/olist/fetch-estoque-v3.php');
$force = (string)file_get_contents($root . '/olist/force-sync-cache.php');
$webhook = (string)file_get_contents($root . '/olist/webhook-receiver.php');

stock_contract_assert(str_contains($fetch, '/public-api/v3/estoque/'), 'estoque precisa vir do endpoint v3 autoritativo');
stock_contract_assert(str_contains($fetch, "array_key_exists('disponivel'"), 'campo disponivel precisa ser a fonte de venda');
stock_contract_assert(str_contains($fetch, "\$item['estoque_disponivel'] = \$estoque"), 'refresh precisa sobrescrever zeros antigos com valor autoritativo');
stock_contract_assert(!str_contains($fetch, "!isset(\$item['estoque_disponivel'])"), 'estoque existente nao pode impedir novo refresh autoritativo');
stock_contract_assert(str_contains($fetch, 'x-ratelimit-remaining'), 'cliente precisa respeitar headers de rate limit');
stock_contract_assert(str_contains($fetch, 'authoritative_stock_coverage_below_90pct'), 'refresh incompleto precisa falhar fechado');
// All storefront prices must originate in Amazon list 991; legacy refresh
// endpoints cannot run a second cache writer with independent locks.
$canonical = (string)file_get_contents($root . '/daemon-sync-products.py');
$legacy = (string)file_get_contents($root . '/olist/sync-on-webhook.php');
stock_contract_assert(str_contains($force, "require __DIR__ . '/sync-on-webhook.php'"), 'force sync should forward to the canonical CLI entry');
stock_contract_assert(!str_contains($force, "require __DIR__ . '/fetch-estoque-v3.php'"), 'manual refresh must not launch a second stock writer');
stock_contract_assert(str_contains($legacy, 'daemon-sync-products.py'), 'legacy sync must delegate to canonical daemon');
stock_contract_assert(!str_contains($legacy, 'file_put_contents('), 'legacy sync may not write the public catalog');
stock_contract_assert(str_contains($webhook, 'svoei_refresh_product_from_v3'), 'webhook must use canonical ERP v3 refresh');
stock_contract_assert(!str_contains($webhook, 'nohup') && !str_contains($webhook, 'sync-on-webhook.php'), 'legacy webhook cannot spawn a competing cache pipeline');
stock_contract_assert(str_contains($fetch, '/storage/olist-erp-webhook.lock'), 'stock refresher must share catalog lock at commit');
stock_contract_assert(str_contains($fetch, 'catalog_changed_during_stock_refresh'), 'stock refresher must reject stale cache revisions');
stock_contract_assert(str_contains($canonical, 'catalog_changed_during_reconcile_retry_later'), 'main reconcile must reject stale cache revisions');

fwrite(STDOUT, "authoritative-stock-refresh-contract: ok\n");
