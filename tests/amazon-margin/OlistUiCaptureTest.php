<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/OlistUiCapture.php';

function checkUi(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException('Olist UI evidence: '.$message);
}
$dir=sys_get_temp_dir().'/sv-amazon-ui-'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);
mkdir($dir.'/evidence',0700,true);
$path=$dir.'/olist-ui-capture.json';
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLttAAAAABJRU5ErkJggg==',true);
checkUi(is_string($png),'png fixture');
$image=$dir.'/evidence/olist-amazon-p1-20261105.png';
file_put_contents($image,$png);
$sha=hash_file('sha256',$image);
$index=['schema'=>1,'source'=>'OLIST_ERP_AUTHENTICATED_UI_CAPTURE',
    'scope'=>['integration'=>'Amazon','integration_id'=>'27473','url_path'=>'/anuncios'],
    'observed_at'=>'2026-11-05T21:45:00.000Z',
    'pages'=>[['page'=>1,'count'=>2,'screenshot_sha256'=>$sha]],
    'entries'=>[
        ['seller_sku'=>'SELLER-1','erp_sku'=>'PROD-01','listed_price'=>'999,99'],
        ['seller_sku'=>'SELLER-2','erp_sku'=>'PROD-02','listed_price'=>'0,01'],
    ]];
$write=static function(array $payload)use($path):void{
    file_put_contents($path,json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    chmod($path,0600);
};
try {
    $write($index);
    $valid=SvAmazonMarginOlistUiCapture::load($path);
    checkUi(count($valid['entries'])===2,'must load full UI evidence');
    checkUi($valid['source']==='OLIST_ERP_AUTHENTICATED_UI_CAPTURE','never masquerade as export');
    $now=new DateTimeImmutable('2026-11-05T22:00:00+00:00');
    $skuIndex=['prod-01'=>[['id'=>101,'sku'=>'PROD-01']], 'prod-02'=>[['id'=>202,'sku'=>'PROD-02']]];
    $linked=SvAmazonMarginOlistUiCapture::lookup($valid,'SELLER-1',$skuIndex,$now);
    checkUi($linked['status']==='mapped' && $linked['id']===101
        && $linked['method']==='OLIST_AUTHENTICATED_UI','fresh exact binding');
    checkUi(!array_key_exists('listed_price',$linked),'UI price must not enter accounting');
    checkUi(SvAmazonMarginOlistUiCapture::lookup($valid,'seller-1',$skuIndex,$now)['status']==='absent',
        'Seller SKU is case-sensitive');
    $duplicate=$skuIndex;
    $duplicate['prod-01'][]=['id'=>102,'sku'=>'PROD-01'];
    checkUi(SvAmazonMarginOlistUiCapture::lookup($valid,'SELLER-1',$duplicate,$now)['status']==='ambiguous_erp',
        'duplicate live ERP SKU must fail closed');
    checkUi(SvAmazonMarginOlistUiCapture::lookup($valid,'SELLER-1',
        ['prod-01'=>[['id'=>101,'sku'=>'prod-01']]],$now)['status']==='ambiguous_erp',
        'normalized SKU may index but never satisfy exact identity');
    $stale=SvAmazonMarginOlistUiCapture::lookup($valid,'SELLER-1',$skuIndex,
        new DateTimeImmutable('2026-11-09T22:00:00+00:00'));
    checkUi($stale['status']==='historical'&&$stale['candidate_erp_sku']==='PROD-01',
        'expired capture becomes reference, never current source');
    $current=['status'=>'mapped','id'=>102,'method'=>'OLIST_AMAZON_ORDER'];
    checkUi(SvAmazonMarginOlistUiCapture::decision($current,['id'=>102,'erp_sku'=>'OLD-PROD'],$linked)==='hold',
        'current UI versus historical order must invalidate certified cost');
    checkUi(SvAmazonMarginOlistUiCapture::decision($current,['id'=>102,'erp_sku'=>'OLD-PROD'],$stale)==='hold',
        'even stale unresolved conflict must not revert to falsely certified cost');
    checkUi(SvAmazonMarginOlistUiCapture::decision(
        ['status'=>'mapped','id'=>101],['id'=>101,'erp_sku'=>'PROD-01'],$linked)==='verified_same',
        'current UI agrees with already confirmed product');
    checkUi(SvAmazonMarginOlistUiCapture::decision(
        ['status'=>'unmapped_erp','reason'=>'no_match'],null,$linked)==='use_ui',
        'unmapped offer may use independently proven current binding');
    checkUi(SvAmazonMarginOlistUiCapture::decision(
        ['status'=>'ambiguous_erp','reason'=>'historical_amazon_orders_disagree_on_product'],
        null,$linked)==='hold','orders in conflict cannot be silently overridden');
    checkUi(SvAmazonMarginOlistUiCapture::decision($current,
        ['id'=>102,'erp_sku'=>'OLD-PROD'],['status'=>'ambiguous_erp'])==='hold',
        'ambiguous UI candidate must not certify cost');
    $bad=$index;
    $bad['source']='OLIST_ERP_ANUNCIOS_EXPORT';
    $write($bad);
    try { SvAmazonMarginOlistUiCapture::load($path);throw new RuntimeException('forged XLS provenance accepted');}
    catch(RuntimeException $e){checkUi($e->getMessage()==='olist_ui_capture_invalid','forged provenance rejected');}
    $bad=$index;
    $bad['entries'][]=$index['entries'][0];
    $bad['pages'][0]['count']=3;
    $write($bad);
    try { SvAmazonMarginOlistUiCapture::load($path);throw new RuntimeException('duplicate seller SKU accepted');}
    catch(RuntimeException $e){checkUi($e->getMessage()==='olist_ui_capture_entry_invalid','duplicate rejected');}
    $write($index);
    file_put_contents($image,'tampered');
    try { SvAmazonMarginOlistUiCapture::load($path);throw new RuntimeException('tampered evidence accepted');}
    catch(RuntimeException $e){checkUi($e->getMessage()==='olist_ui_screenshot_integrity_failed','screenshot hash enforced');}
    echo "PASS Olist authenticated UI: source integrity, 48h, active unique ERP SKU, conflict fail-closed and tamper negatives\n";
} finally {
    @unlink($image);@unlink($path);@rmdir($dir.'/evidence');@rmdir($dir);
}
