<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/OlistUiCapture.php';

function assertMulti(bool $pass,string $message):void {
    if (!$pass) throw new RuntimeException('Olist UI channels test: '.$message);
}
$root=sys_get_temp_dir().'/amazon-ui-channels-'.bin2hex(random_bytes(6));
mkdir($root,0700,true);
mkdir($root.'/evidence',0700,true);
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLttAAAAABJRU5ErkJggg==',true);
assertMulti(is_string($png),'PNG fixture invalid');
$files=[];
$images=[];
$write=static function(string $channel,string $filename,string $when,array $entries)
    use($root,$png,&$files,&$images):void {
    $slug=strtolower(str_replace(' ','-',$channel));
    $stamp=(new DateTimeImmutable($when))->setTimezone(new DateTimeZone('UTC'))->format('Ymd');
    $image=$root.'/evidence/olist-'.$slug.'-p1-'.$stamp.'.png';
    file_put_contents($image,$png);
    $images[]=$image;
    $index=[
        'schema'=>1,
        'source'=>'OLIST_ERP_AUTHENTICATED_UI_CAPTURE',
        'scope'=>['integration'=>$channel,'integration_id'=>match($channel){
            'Amazon'=>'27473','Amazon Onsite'=>'27478','Amazon Classic'=>'27482',
        },'url_path'=>'/anuncios'],
        'observed_at'=>$when,
        'pages'=>[['page'=>1,'count'=>count($entries),
            'screenshot_sha256'=>hash_file('sha256',$image)]],
        'entries'=>array_map(static fn(array $row):array=>[
            'seller_sku'=>$row[0], 'erp_sku'=>$row[1], 'listed_price'=>'0,01',
        ],$entries),
    ];
    $file=$root.'/'.$filename;
    file_put_contents($file,json_encode($index,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    chmod($file,0600);
    $files[]=$file;
};
try {
    $write('Amazon','olist-ui-capture.json','2026-11-05T21:40:00.000Z',
        [['SELLER-A','ERP-A'],['SELLER-D','ERP-D']]);
    $write('Amazon Onsite','olist-ui-capture-amazon-onsite.json','2026-11-05T21:42:00.000Z',
        [['SELLER-B','ERP-B']]);
    $write('Amazon Classic','olist-ui-capture-amazon-classic.json','2026-11-05T21:44:00.000Z',
        [['SELLER-C','ERP-C'],['SELLER-A','ERP-X'],['SELLER-D','ERP-D']]);
    $capture=SvAmazonMarginOlistUiCapture::loadAll($root.'/olist-ui-capture.json');
    assertMulti(count($capture['integrations'])===3,'three independent integrations not loaded');
    assertMulti(count($capture['entries'])===4,'unique seller SKU union broken');
    $products=[
        'erp-a'=>[['id'=>1,'sku'=>'ERP-A']],
        'erp-b'=>[['id'=>2,'sku'=>'ERP-B']],
        'erp-c'=>[['id'=>3,'sku'=>'ERP-C']],
        'erp-d'=>[['id'=>4,'sku'=>'ERP-D']],
        'erp-x'=>[['id'=>5,'sku'=>'ERP-X']],
    ];
    $at=new DateTimeImmutable('2026-11-05T22:00:00Z');
    foreach([['SELLER-B',2,'Amazon Onsite'],['SELLER-C',3,'Amazon Classic'],
             ['SELLER-D',4,'Amazon Classic']] as [$sku,$id,$channel]){
        $link=SvAmazonMarginOlistUiCapture::lookup($capture,$sku,$products,$at);
        assertMulti($link['status']==='mapped' && $link['id']===$id
            && $link['integration']===$channel,'exact channel mapping '.$sku);
        assertMulti(!isset($link['listed_price']),'cost/price must never derive from Olist UI capture');
    }
    $conflict=SvAmazonMarginOlistUiCapture::lookup($capture,'SELLER-A',$products,$at);
    assertMulti($conflict['status']==='ambiguous_erp'
        && $conflict['reason']==='olist_ui_integrations_product_conflict',
        'contradictory Seller SKU from different integrations must fail closed');
    assertMulti(SvAmazonMarginOlistUiCapture::decision(
        ['status'=>'mapped','id'=>1],['id'=>1,'erp_sku'=>'ERP-A'],$conflict)==='hold',
        'conflict must block an earlier matched cost');
    $later=SvAmazonMarginOlistUiCapture::lookup($capture,'SELLER-C',$products,
        new DateTimeImmutable('2026-11-09T22:00:00Z'));
    assertMulti($later['status']==='historical','expired channel capture cannot certify current cost');
    $bad=$root.'/olist-ui-capture-amazon-classic.json';
    $data=json_decode(file_get_contents($bad),true,512,JSON_THROW_ON_ERROR);
    $data['scope']['integration']='Amazon';
    file_put_contents($bad,json_encode($data,JSON_THROW_ON_ERROR));
    try { SvAmazonMarginOlistUiCapture::loadAll($root.'/olist-ui-capture.json');
        throw new RuntimeException('mislabelled channel accepted'); }
    catch (RuntimeException $e) {
        assertMulti($e->getMessage()==='olist_ui_capture_channel_mismatch','channel file swap rejected');
    }
    @unlink($bad);
    $fallback=SvAmazonMarginOlistUiCapture::loadAll($root.'/olist-ui-capture.json');
    assertMulti(count($fallback['integrations'])===2,'missing third capture should not hide valid ones');
    echo "PASS Olist UI multi-channel: exact isolated provenance, 3 sources, collision hold, expiry and channel integrity\n";
} finally {
    foreach($files as $file) @unlink($file);
    foreach($images as $image) @unlink($image);
    @rmdir($root.'/evidence');
    @rmdir($root);
}
