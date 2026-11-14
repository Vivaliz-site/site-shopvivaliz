<?php
declare(strict_types=1);
// Regression: the sync script read $latest['payload'] while
// SvAmazonMarginStore::latest() returns the report under 'snapshot', so the
// script always failed with latest_audit_snapshot_missing in production.
$_SERVER['SCRIPT_FILENAME']=__FILE__; // keep the CLI body from running on include
require_once __DIR__.'/../../scripts/amazon-margin/sync-erp-packages.php';
function checkSync(bool $ok,string $case):void{
    if(!$ok)throw new RuntimeException('sync_erp_packages_snapshot_'.$case);
}
$rows=[['seller_sku'=>'A'],['seller_sku'=>'B']];
checkSync(sv_amazon_latest_snapshot_rows(['run_id'=>1,'snapshot'=>['rows'=>$rows]])===$rows,'reads_snapshot_rows');
checkSync(sv_amazon_latest_snapshot_rows(['payload'=>['rows'=>$rows]])===[],'old_payload_key_is_not_accepted');
checkSync(sv_amazon_latest_snapshot_rows(null)===[],'null_is_empty');
checkSync(sv_amazon_latest_snapshot_rows(['snapshot'=>['rows'=>'x']])===[],'non_array_rows_is_empty');
echo "PASS sync-erp-packages: reads latest snapshot rows, rejects missing/legacy shape\n";
