<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/OlistAdsIndex.php';
function ok(bool $assert,string $message):void {
    if (!$assert)throw new RuntimeException('OlistAdsIndex: '.$message);
}
$now=new DateTimeImmutable('2026-11-04T14:00:00+00:00');
$index=['schema'=>1,'source'=>'OLIST_ERP_ANUNCIOS_EXPORT','entries'=>[
    '03SFBA'=>[
        'status'=>'unique','erp_sku'=>'03S',
        'integrations'=>['Amazon Onsite'],'observed_at'=>'2026-11-04T13:03:00+00:00',
        'fresh'=>true
    ],
]];
$products=['03s'=>[['id'=>341440900,'sku'=>'03S','gtin'=>'1236547898568']]];
$linked=SvAmazonMarginOlistAdsIndex::lookup($index,'03SFBA',$products,$now);
ok($linked['status']==='mapped'&&$linked['id']===341440900,'valid recent exact ad link');
ok($linked['method']==='OLIST_ADS_EXPORT','provenance must be export-specific');
ok($linked['integration']==='Amazon Onsite','integration must be preserved');
ok(SvAmazonMarginOlistAdsIndex::lookup($index,'03sfba',$products,$now)['status']==='absent',
    'Seller SKU case must be exact');
$stale=$index;
$stale['entries']['03SFBA']['observed_at']='2026-07-27T12:51:25+00:00';
$old=SvAmazonMarginOlistAdsIndex::lookup($stale,'03SFBA',$products,$now);
ok($old['status']==='historical'&&$old['id']===null
    &&$old['candidate_erp_sku']==='03S','old export cannot certify current cost');
$untrusted=$index;
$untrusted['entries']['03SFBA']['fresh']=false;
ok(SvAmazonMarginOlistAdsIndex::lookup($untrusted,'03SFBA',$products,$now)['status']==='historical',
    'no fresh flag means reference only');
$duplicate=$products;
$duplicate['03s'][]=['id'=>341440901,'sku'=>'03S'];
ok(SvAmazonMarginOlistAdsIndex::lookup($index,'03SFBA',$duplicate,$now)['status']==='ambiguous_erp',
    'duplicate live SKU must never resolve');
$wrong=['03s'=>[['id'=>341440900,'sku'=>'03s']]];
ok(SvAmazonMarginOlistAdsIndex::lookup($index,'03SFBA',$wrong,$now)['status']==='ambiguous_erp',
    'ERP SKU string must match exactly');
$invalid=$index;$invalid['entries']['03SFBA']['integrations']=['Shopee'];
ok(SvAmazonMarginOlistAdsIndex::lookup($invalid,'03SFBA',$products,$now)['status']==='absent',
    'unapproved integration rejected');
$conflict=$index;
$conflict['entries']['03SFBA']['status']='conflicting';
$conflict['entries']['03SFBA']['erp_sku']=null;
ok(SvAmazonMarginOlistAdsIndex::lookup($conflict,'03SFBA',$products,$now)['status']==='ambiguous_erp',
    'conflicting mapping cannot certify cost');
$tmp=tempnam(sys_get_temp_dir(),'sv-olist-ads-test-');
try {
    file_put_contents($tmp,json_encode($index,JSON_THROW_ON_ERROR));
    $loaded=SvAmazonMarginOlistAdsIndex::load($tmp);
    ok(isset($loaded['entries']['03SFBA']),'valid source loaded');
    file_put_contents($tmp,'{"schema":1,"source":"FAKE","entries":{}}');
    try {
        SvAmazonMarginOlistAdsIndex::load($tmp);
        throw new RuntimeException('forged source accepted');
    } catch(RuntimeException $e) {
        ok($e->getMessage()==='olist_ads_index_invalid','reject unknown index source');
    }
} finally {
    @unlink($tmp);
}
echo "PASS Olist ad export: exact SKU, fresh 48h, 3 integrations, stale/noncertified, duplicates, source validation\n";
