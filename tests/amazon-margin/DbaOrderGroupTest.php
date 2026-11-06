<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/DbaOrderGroup.php';
$expect=static function(bool $ok,string $why):void {
    if (!$ok) throw new RuntimeException('DBA group regression: '.$why);
};
$rows=[
    ['order'=>'111-1234567-1234567','quantity'=>1,'posted_at'=>'2026-11-01T10:00:00Z',
        'net_amount'=>29.83,'sale_gross_cents'=>4200],
    ['order'=>'111-1234567-1234567','quantity'=>2,'posted_at'=>'2026-11-02T10:00:00Z',
        'net_amount'=>59.66,'sale_gross_cents'=>8400],
    ['order'=>'222-1234567-1234567','quantity'=>1,'posted_at'=>'2026-11-01T10:00:00Z',
        'net_amount'=>18.50,'sale_gross_cents'=>2600],
];
$groups=SvAmazonMarginDbaOrderGroup::combine($rows);
$expect(count($groups)===2,'distinct orders must remain separate');
$first=$groups[0];
$expect($first['source_rows']===2 && $first['quantity']===3
    && $first['net_amount']===89.49 && $first['sale_gross_cents']===12600,
    'same-order three units need one financial aggregate; postage is once per order');
$calc=SvAmazonMarginMath::calculate([
    'sku'=>'DBA_MULTI_SHIPMENT','program'=>'DBA','price'=>42,'cost'=>19.45,
    'quantity'=>$first['quantity'],'shipment_total'=>$first['net_amount'],
    'sale_gross_cents'=>$first['sale_gross_cents'],
    'postage_total'=>-12.00,'postage_confirmed'=>true,
]);
$expect($calc['status']==='confirmed','full order must be certifiable');
$expectedProfit=8949-1200-1945*3-1764;
$expect($calc['profit_cents']===$expectedProfit,'postage once, kit COGS three times, 14 percent on real gross');
$expect($calc['gross_cents']===12600,'actual gross sum, not live price times three');
$missing=$rows;
$missing[1]['sale_gross_cents']=null;
$bad=SvAmazonMarginDbaOrderGroup::combine($missing);
$expect($bad[0]['sale_gross_cents']===null,'incomplete shipment financial breakdown must fail closed');
$expect(SvAmazonMarginMath::calculate([
    'sku'=>'DBA_BAD','program'=>'DBA','price'=>42,'cost'=>19.45,
    'quantity'=>$bad[0]['quantity'],'shipment_total'=>$bad[0]['net_amount'],
    'sale_gross_cents'=>$bad[0]['sale_gross_cents'],
    'postage_total'=>-12.00,'postage_confirmed'=>true,
])['status']==='unverified','bad gross cannot certify full order');
$unknown=$rows;
$unknown[0]['order']='';
$unknown[1]['order']='';
$isolated=SvAmazonMarginDbaOrderGroup::combine($unknown);
$expect(count($isolated)===3,'missing order IDs must never be grouped together');
$expect($groups[1]['source_rows']===1 && $groups[1]['quantity']===1,
    'independent second order is not charged with first order postage');
echo "PASS DBA multi-shipment aggregation: order fees once, quantities, tax, isolation and failed gross\n";
