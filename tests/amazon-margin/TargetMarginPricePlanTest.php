<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/TargetMarginPricePlan.php';

function assertTarget(bool $ok,string $name):void {
    if(!$ok)throw new RuntimeException('target_margin_price_plan_'.$name);
}
$sku=[
    'seller_sku'=>'FBA_SKU','title'=>'Armario teste','asin'=>'B000000001',
    'program'=>'FBA_ONSITE','amazon_status'=>['BUYABLE'],
    'price'=>104.62,'last_cost'=>56.29,
    'current_commission_cents'=>1569,'current_logistics_cents'=>1635,
    'current_billable_kg'=>1.3,'current_margin_bps'=>157,
    'current_profit_cents'=>164,'current_package_conflict'=>false,
    'erp_id'=>250,'erp_sku'=>'A41','status'=>'no_confirmed_sales',
    '_target_tax_bps'=>1400
];
$same=$sku;$same['seller_sku']='FBA_OTHER';$same['price']=114.62;
$independent=$sku;$independent['seller_sku']='DBA_OTHER';$independent['erp_id']=280;
$independent['program']='DBA';$independent['current_billable_kg']=0.8;
$independent['price']=79.9;$independent['current_commission_cents']=990;
$other=$sku;$other['seller_sku']='OTHER_UNSELECTED';$other['erp_id']=250;
$source=['rows'=>[$sku,$same,$independent,$other]];
assertTarget(SvAmazonTargetMarginPricePlan::targetBps('10')===1000,'target_percent_to_bps');
assertTarget(SvAmazonTargetMarginPricePlan::targetBps('12.45')===1245,'hundredth_of_percent');
foreach(['0','100','no',null] as $bad){
    try{SvAmazonTargetMarginPricePlan::targetBps($bad);throw new RuntimeException('invalid margin accepted');}
    catch(InvalidArgumentException $e){assertTarget(str_starts_with($e->getMessage(),'margin_target'),'input_guard');}
}
$before=json_encode($source,JSON_THROW_ON_ERROR);
$p=SvAmazonTargetMarginPricePlan::prepare($source,['FBA_SKU'],1000,'other');
assertTarget($p['selected']===1&&$p['simulated']===1&&$p['pending']===0,'one_sku_prepared');
$r=$p['rows'][0];
assertTarget($r['state']==='simulated'&&$r['proposed_price_cents']>=10462,'proposed_only_increase');
assertTarget($r['minimum_price_cents']===$r['proposed_price_cents'],'minimum_equals_sale');
assertTarget($r['profit_estimate_cents']*10000 >=1000*$r['proposed_price_cents'],'target_met_in_integer_cents');
assertTarget($r['erp_id']===250&&$r['erp_sku']==='A41','erp_binding');
assertTarget(in_array('FBA_OTHER',$r['related_unselected_skus'],true)
    && in_array('OTHER_UNSELECTED',$r['related_unselected_skus'],true),'shared_erp_impact');
assertTarget($p['modifies_prices']===false
    && $p['authoritative_quote_at_target']===false,'simulation_only');
assertTarget(json_encode($source,JSON_THROW_ON_ERROR)===$before,'snapshot_never_mutated');

$atPrice=SvAmazonTargetMarginPricePlan::prepare($source,['FBA_SKU'],100,'other');
assertTarget($atPrice['rows'][0]['proposed_price_cents']===10462,'no_decrease_when_already_high_enough');
$shared=SvAmazonTargetMarginPricePlan::prepare($source,['FBA_SKU','FBA_OTHER'],1000,'other');
assertTarget($shared['selected']===2,'multi_sku_selection');
assertTarget($shared['rows'][0]['price_list_shared_product_conflict']===true
    && $shared['rows'][1]['price_list_shared_product_conflict']===true,
    'different_prices_same_erp_id_conflict');

$invalid=$sku;$invalid['seller_sku']='MISSING_COST';$invalid['last_cost']=null;
$missing=SvAmazonTargetMarginPricePlan::prepare(['rows'=>[$invalid]],['MISSING_COST'],1000,'other');
assertTarget($missing['rows'][0]['state']==='pending'
    && $missing['rows'][0]['reason']==='unit_cost_unverified','no_invented_cost');
$off=$sku;$off['seller_sku']='INACTIVE';$off['amazon_status']=['DISCOVERABLE'];
$pending=SvAmazonTargetMarginPricePlan::prepare(['rows'=>[$off]],['INACTIVE','UNKNOWN'],1000);
assertTarget($pending['pending']===2&&$pending['simulated']===0,'not_buyable_or_unknown');
$candidate=$sku;$candidate['seller_sku']='DBA_CANDIDATE';$candidate['program']='DBA_CANDIDATE';
$cand=SvAmazonTargetMarginPricePlan::prepare(['rows'=>[$candidate]],['DBA_CANDIDATE'],1000,'other');
assertTarget(in_array('programa_dba_ainda_candidato',$cand['rows'][0]['notices'],true),
    'hypothesized_program_visible');

$cheap=$sku;$cheap['seller_sku']='CHEAP';
$cheap['price']=29.99;$cheap['last_cost']=10.00;
$cheap['current_commission_cents']=450;$cheap['current_billable_kg']=null;
$cheap['current_margin_bps']=1600;
$cross=SvAmazonTargetMarginPricePlan::prepare(['rows'=>[$cheap]],['CHEAP'],3000,'other');
assertTarget($cross['rows'][0]['state']==='simulated','tariff_boundary_resolved');
assertTarget($cross['rows'][0]['proposed_price_cents']>3000,'tariff_boundary_crossed');
assertTarget($cross['rows'][0]['profit_estimate_cents']*10000>=3000*$cross['rows'][0]['proposed_price_cents'],
    'tariff_boundary_target');

$loss=$sku;$loss['seller_sku']='NEGATIVE_MARGIN';
$loss['price']=137.00;$loss['last_cost']=99.99;
$loss['current_margin_bps']=-1167;$loss['current_commission_cents']=1507;
$loss['current_billable_kg']=1.02;$loss['program']='DBA';
$loss['erp_id']=353733531;$loss['erp_sku']='Sabatini';
$lossPlan=SvAmazonTargetMarginPricePlan::prepare(
    ['rows'=>[$loss]],['NEGATIVE_MARGIN'],900,'se_interior');
$lossRow=$lossPlan['rows'][0];
assertTarget($lossRow['state']==='simulated','negative_margin_not_mistaken_for_missing');
assertTarget($lossRow['proposed_price_cents']>=13700,'negative_margin_price_never_reduced');
assertTarget($lossRow['profit_estimate_cents']*10000>=900*$lossRow['proposed_price_cents'],
    'negative_margin_target_nine_percent_in_cents');
$uncomputed=$loss;$uncomputed['seller_sku']='UNCOMPUTED_MARGIN';
$uncomputed['current_margin_bps']=null;
$missingMargin=SvAmazonTargetMarginPricePlan::prepare(
    ['rows'=>[$uncomputed]],['UNCOMPUTED_MARGIN'],900,'se_interior');
assertTarget($missingMargin['rows'][0]['state']==='pending'
    && $missingMargin['rows'][0]['reason']==='current_margin_not_calculated',
    'null_margin_still_pending');

echo "PASS Amazon target-margin price simulation: cents, tariffs, ERP 991 groups, no writes, projections, missing data\n";
