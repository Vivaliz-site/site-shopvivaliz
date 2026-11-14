<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/CurrentPriceMargin.php';
function same(mixed $actual,mixed $expected,string $label):void
{
    if($actual!==$expected)throw new RuntimeException($label.' actual='.var_export($actual,true).' expected='.var_export($expected,true));
}
$marketplace='A2Q3Y263D00KWC';
$amazon=SvAmazonCurrentPriceMargin::amazonPackage([
 'item_package_weight'=>[['unit'=>'grams','value'=>19800]],
 'item_package_dimensions'=>[['length'=>['unit'=>'centimeters','value'=>61],
 'width'=>['unit'=>'centimeters','value'=>41],'height'=>['unit'=>'centimeters','value'=>69]]]
]);
$erp=SvAmazonCurrentPriceMargin::erpPackage([
 'pesoBruto'=>28.5,'largura'=>42,'altura'=>69,'comprimento'=>62
]);
if(!is_array($amazon)||!is_array($erp))throw new RuntimeException('valid packages lost');
same(round(SvAmazonCurrentPriceMargin::billable($amazon)['billable_kg'],4),28.7815,'c08 billable volumetric');
same(SvAmazonCurrentPriceMargin::logisticsCents('FBA_ONSITE',154900,28.7815,null),14190,'FBA onsite rate');
same(SvAmazonCurrentPriceMargin::logisticsCents('DBA',154900,29.966,'se_interior'),14595,'DBA region rate');
same(SvAmazonCurrentPriceMargin::logisticsCents('DBA',154900,29.966,null),null,'DBA region required');
$row=['seller_sku'=>'C08BR','amazon_status'=>['BUYABLE'],'program'=>'FBA_ONSITE',
 'price'=>1549,'last_cost'=>900,'amazon_package'=>$amazon,'erp_package'=>$erp,'kit_cost_multiplier'=>1,
 'confirmed_units'=>0,'margin_weighted_bps'=>null];
$q=['price_cents'=>154900,'commission_cents'=>17039];
$r=SvAmazonCurrentPriceMargin::calculate($row,$q);
same($r['current_profit_cents'],11985,'profit based on current selling price');
same($r['current_margin_bps'],774,'margin bps');
same($r['current_package_conflict'],true,'must surface 19.8kg Amazon vs 28.5kg Olist weight');
same($r['current_margin_status'],'estimated_dimension_conflict','not certified with conflicting measures');
same($r['current_logistics_cents'],14190,'Onsite current freight');
same($r['current_tax_cents'],21686,'tax on current price');
$row['program']='DBA_CANDIDATE';$row['amazon_package']=null;
$z=SvAmazonCurrentPriceMargin::calculate($row,['price_cents'=>154900,'commission_cents'=>16490],1400,'se_interior');
same($z['current_margin_status'],'estimated_program_unverified','no historical sales must still project but flag DBA assumed');
same($z['current_profit_cents'],12129,'DBA current profit');
same($z['current_margin_bps'],783,'DBA current margin');
same(SvAmazonCurrentPriceMargin::calculate($row,null,1400,'se_interior')['current_margin_bps'],null,'missing fee never fake margin');
same(SvAmazonCurrentPriceMargin::calculate($row,['price_cents'=>141779,'commission_cents'=>16490],1400,'se_interior')['current_margin_status'],'fee_quote_price_mismatch','historical quoted price rejected');
$row['last_cost']=null;same(SvAmazonCurrentPriceMargin::calculate($row,$q,1400,'se_interior')['current_margin_bps'],null,'unknown kit cost must stay null');
$row['last_cost']=900;$row['amazon_status']=['DISCOVERABLE'];same(SvAmazonCurrentPriceMargin::calculate($row,$q,1400,'se_interior')['current_margin_status'],'not_buyable','inactive excluded');
$antique55=[
 'seller_sku'=>'JVAQCQ55','amazon_status'=>['BUYABLE'],'program'=>'FBA_ONSITE',
 'price'=>352.90,'last_cost'=>148.00,'kit_cost_multiplier'=>1,
 'amazon_package'=>['weight_kg'=>3.3,'length_cm'=>55,'width_cm'=>55,'height_cm'=>90],
 'erp_package'=>['weight_kg'=>3.3,'length_cm'=>55,'width_cm'=>55,'height_cm'=>52],
];
$antiqueCalc=SvAmazonCurrentPriceMargin::calculate($antique55,['price_cents'=>35290,'commission_cents'=>5294],1400);
same($antiqueCalc['current_package_source'],'OLIST_V3_REGISTERED_PACKAGE','fallback to valid ERP package when Amazon package is outside program limits');
same($antiqueCalc['current_billable_kg'],26.2367,'ERP package billable weight used');
same($antiqueCalc['current_package_conflict'],true,'conflict still flagged for catalog sync');
echo "PASS current-price-margin: C08, DBA without sales, ERP/Amazon physical conflict, ERP package fallback, missing cost, fee mismatch, inactive\n";
