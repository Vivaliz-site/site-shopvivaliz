<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$must = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$files = [
    'api/olist/webhook-receiver.php',
    'olist/webhook-receiver.php',
    'api/sync-cache-endpoint.php',
    'olist/sync-on-webhook.php',
    'olist/force-sync-cache.php',
    'olist/fetch-estoque-v3.php',
    'daemon-sync-products.py',
];
foreach ($files as $file) $must(is_file($root . '/' . $file), "missing {$file}");
$apiLegacy = (string)file_get_contents($root . '/' . $files[0]);
$olistLegacy = (string)file_get_contents($root . '/' . $files[1]);
$manual = (string)file_get_contents($root . '/' . $files[2]);
$oldSync = (string)file_get_contents($root . '/' . $files[3]);
$force = (string)file_get_contents($root . '/' . $files[4]);
$oldStock = (string)file_get_contents($root . '/' . $files[5]);
$daemon = (string)file_get_contents($root . '/' . $files[6]);

foreach ([$apiLegacy, $olistLegacy] as $source) {
    $must(str_contains($source, 'svoei_refresh_product_from_v3'), 'legacy webhook must query ERP v3');
    $must(str_contains($source, 'SV_AMAZON_PRICE_LIST_ID'), 'legacy webhook must use Amazon source');
    $must(str_contains($source, "REQUEST_METHOD") && str_contains($source, 'POST'), 'legacy webhooks POST only');
    $must(!str_contains($source, 'nohup') && !str_contains($source, 'trigger_cache_resync')
        && !str_contains($source, 'rename($cache_file')
        && !str_contains($source, 'sync-on-webhook.php'), 'legacy route cannot launch a competing writer or remove live cache');
}
$must(str_contains($apiLegacy, 'hash_equals') && str_contains($apiLegacy, 'sv_webhook_secret_valid'),
    'legacy Olist API path must require a configured secret');
$must(str_contains($olistLegacy, 'hash_hmac') && str_contains($olistLegacy, 'sv_webhook_secret_valid'),
    'legacy Olist notification must reject unsigned anonymous traffic');
$must(!str_contains($olistLegacy, 'json_encode($decodedForLog'),
    'legacy Olist notification must not log full payload');
$must(str_contains($manual, 'legacy_catalog_rebuild_retired') && !str_contains($manual, 'file_put_contents('),
    'manual HTTP cache overwrite must be retired');
$must(str_contains($oldSync, 'daemon-sync-products.py') && !str_contains($oldSync, 'file_put_contents('),
    'legacy list-price writer must forward to canonical');
$must(str_contains($force, "PHP_SAPI !== 'cli'") && !str_contains($force, 'fetch-estoque-v3.php'),
    'manual sync cannot invoke duplicate stock writer');
$must(str_contains($oldStock, 'catalog_changed_during_stock_refresh')
    && str_contains($oldStock, 'olist-erp-webhook.lock'),
    'stock updater needs cross-writer CAS lock');
$must(str_contains($daemon, 'catalog_changed_during_reconcile_retry_later')
    && str_contains($daemon, 'olist-erp-webhook.lock'),
    'daemon needs cross-writer CAS lock');
echo "catalog-price-writer-safety-contract: ok\n";
