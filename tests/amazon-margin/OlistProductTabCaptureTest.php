<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/OlistProductTabCapture.php';
require_once __DIR__.'/../../includes/amazon-margin/OlistUiCapture.php';

function assertProductTab(bool $value,string $message):void {
    if(!$value)throw new RuntimeException('product_tab_evidence: '.$message);
}
$root=sys_get_temp_dir().'/sv-olist-product-tab-'.bin2hex(random_bytes(5));
$evidence=$root.'/evidence/olist-product-tabs';
mkdir($evidence,0700,true);
$path=$root.'/olist-product-links-current.json';
$png=base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLttAAAAABJRU5ErkJggg==',true);
assertProductTab(is_string($png),'valid PNG fixture');
$image=$evidence.'/product-101-20261106.png';
file_put_contents($image,$png);
chmod($image,0600);
$sha=hash_file('sha256',$image);
$base=[
    'schema'=>1,
    'source'=>'OLIST_ERP_AUTHENTICATED_PRODUCT_EDIT_UI',
    'active_count'=>2,
    'observed_at'=>'2026-11-06T04:15:00.000Z',
    'input_observed_at'=>'2026-11-06T04:00:00+00:00',
    'channels'=>[
        '27473'=>'Amazon',
        '27478'=>'Amazon Onsite',
        '27482'=>'Amazon Classic',
    ],
    'errors'=>[],
    'records'=>[
        '101'=>[
            'product_id'=>101,'erp_sku'=>'REAL-ERP',
            'url_path'=>'/produtos#edit/101',
            'observed_at'=>'2026-11-06T04:15:00.000Z',
            'bindings'=>[
                ['seller_sku'=>'SELLER-1','channel_id'=>'27478',
                    'channel'=>'Amazon Onsite','situacao'=>'A','status'=>'1',
                    'mapping_id'=>'101010'],
                ['seller_sku'=>'DISABLED','channel_id'=>'27473',
                    'channel'=>'Amazon','situacao'=>'I','status'=>'0',
                    'mapping_id'=>'101012'],
            ],
            'screenshot_sha256'=>$sha,
        ],
        '102'=>[
            'product_id'=>102,'erp_sku'=>'OTHER-ERP',
            'url_path'=>'/produtos#edit/102',
            'observed_at'=>'2026-11-06T04:16:00.000Z',
            'bindings'=>[
                ['seller_sku'=>'SELLER-2','channel_id'=>'27482',
                    'channel'=>'Amazon Classic','situacao'=>'A','status'=>'1',
                    'mapping_id'=>'102010'],
            ],
        ],
    ],
];
$write=static function(array $data)use($path):void {
    file_put_contents($path,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    chmod($path,0600);
};
try {
    $write($base);
    $loaded=SvAmazonMarginOlistProductTabCapture::load($path);
    assertProductTab($loaded['active_count']===2,'complete active product census');
    assertProductTab(!isset($loaded['entries']['DISABLED']),'inactive link must not certify');
    $live=['real-erp'=>[['id'=>101,'sku'=>'REAL-ERP']],
           'other-erp'=>[['id'=>102,'sku'=>'OTHER-ERP']]];
    $now=new DateTimeImmutable('2026-11-06T05:00:00+00:00');
    $one=SvAmazonMarginOlistProductTabCapture::lookup($loaded,'SELLER-1',$live,$now);
    assertProductTab($one['status']==='mapped' && $one['id']===101
        && $one['method']==='OLIST_AUTHENTICATED_PRODUCT_TAB_UI',
        'verified screenshot + unique live ERP SKU must map');
    // Same Amazon Seller SKU can be listed under both integrations of ONE
    // Olist product. Fulfillment decides which integration supplies the rate.
    $sameProductChannels=$loaded;
    $sameProductChannels['entries']['SELLER-1'][]=[
        'id'=>101,'erp_sku'=>'REAL-ERP','integration'=>'Amazon',
        'observed_at'=>'2026-11-06T04:15:00.000Z','screenshot_verified'=>true];
    $asDba=SvAmazonMarginOlistProductTabCapture::lookup(
        $sameProductChannels,'SELLER-1',$live,$now,'MERCHANT');
    assertProductTab($asDba['status']==='mapped' && $asDba['integration']==='Amazon',
        'merchant current offer selects Olist Amazon/DBA integration');
    $asOnsite=SvAmazonMarginOlistProductTabCapture::lookup(
        $sameProductChannels,'SELLER-1',$live,$now,'AMAZON');
    assertProductTab($asOnsite['status']==='mapped'
        && $asOnsite['integration']==='Amazon Onsite',
        'Amazon fulfillment selects Olist Amazon Onsite integration');
    assertProductTab(SvAmazonMarginOlistProductTabCapture::lookup(
        $loaded,'SELLER-2',$live,$now)['status']==='absent',
        'unpictured product association must not certify missing cost');
    assertProductTab(SvAmazonMarginOlistProductTabCapture::lookup(
        $loaded,'seller-1',$live,$now)['status']==='absent','exact case-sensitive Seller SKU');
    assertProductTab(!array_key_exists('price',$one)
        && !array_key_exists('cost',$one),'UI cannot supply accounting amounts');
    assertProductTab(SvAmazonMarginOlistProductTabCapture::lookup($loaded,'SELLER-1',
        ['real-erp'=>[['id'=>999,'sku'=>'REAL-ERP']]],$now)['status']==='ambiguous_erp',
        'ERP product-id change is not an accepted association');
    assertProductTab(SvAmazonMarginOlistProductTabCapture::lookup($loaded,'SELLER-1',
        $live,new DateTimeImmutable('2026-11-10T04:16:00+00:00'))['status']==='historical',
        'stale capture is only historical reference');
    assertProductTab(SvAmazonMarginOlistUiCapture::decision(
        ['status'=>'mapped','id'=>102],['erp_sku'=>'OTHER-ERP','id'=>102],$one)==='hold',
        'UI must never override contradictory verified order/ERP link');
    $conflict=$base;
    $conflict['records']['102']['bindings'][0]['seller_sku']='SELLER-1';
    $write($conflict);
    $dupes=SvAmazonMarginOlistProductTabCapture::load($path);
    assertProductTab(SvAmazonMarginOlistProductTabCapture::lookup(
        $dupes,'SELLER-1',$live,$now)['status']==='ambiguous_erp',
        'two ERP products claiming one Seller SKU must fail closed');
    $invalid=$base;
    unset($invalid['records']['102']);
    $write($invalid);
    try {SvAmazonMarginOlistProductTabCapture::load($path);
        throw new RuntimeException('incomplete capture accepted');}
    catch(RuntimeException $ex) {
        assertProductTab($ex->getMessage()==='olist_product_tab_capture_incomplete_or_invalid',
            'missing active product invalidates whole capture');
    }
    $badSource=$base;
    $badSource['source']='OLIST_ERP_ANUNCIOS_EXPORT';
    $write($badSource);
    try {SvAmazonMarginOlistProductTabCapture::load($path);
        throw new RuntimeException('XLS provenance masquerade accepted');}
    catch(RuntimeException $ex) {
        assertProductTab($ex->getMessage()==='olist_product_tab_capture_incomplete_or_invalid',
            'new source cannot pretend to be official export');
    }
    $unkeyed=$base;
    $unkeyed['active_count']=3;
    $unkeyed['records']['103']=[
        'product_id'=>103,'erp_sku'=>'',
        'url_path'=>'/produtos#edit/103',
        'observed_at'=>'2026-11-06T04:17:00.000Z',
        'bindings'=>[],
    ];
    $write($unkeyed);
    assertProductTab(SvAmazonMarginOlistProductTabCapture::load($path)['active_count']===3,
        'active Olist option placeholder without SKU or ads counts in census');
    $unkeyed['records']['103']['bindings']=$base['records']['102']['bindings'];
    $write($unkeyed);
    try {SvAmazonMarginOlistProductTabCapture::load($path);
        throw new RuntimeException('unkeyed product ad accepted');}
    catch(RuntimeException $ex) {
        assertProductTab($ex->getMessage()==='olist_product_tab_record_invalid',
            'unkeyed ERP product can never certify an Amazon link');
    }
    $write($base);
    file_put_contents($image,'tampered');
    try {SvAmazonMarginOlistProductTabCapture::load($path);
        throw new RuntimeException('tampered screenshot accepted');}
    catch(RuntimeException $ex) {
        assertProductTab($ex->getMessage()==='olist_product_tab_screenshot_integrity_failed',
            'screenshot hash must be checked before trusting links');
    }
    echo "PASS complete active Olist product-tab capture; screenshots, identity, case, stale, conflict, provenance, tamper\n";
} finally {
    @unlink($image);
    @unlink($path);
    @rmdir($evidence);
    @rmdir($root.'/evidence');
    @rmdir($root);
}
