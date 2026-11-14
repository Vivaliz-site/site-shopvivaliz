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


// The owner explicitly excludes every Fercar tool cart from price changes.
// Ten live Seller SKUs span six ERP products; one title omits "ferramenta",
// so the stable ERP identity/SKU exclusion is also required.
$toolCarts=[
 ['seller_sku'=>'C08BR','erp_id'=>353735815,'erp_sku'=>'C08BR','title'=>'Carrinho Fechado Branco com 6 Gavetas-FERCAR-C08BR'],
 ['seller_sku'=>'C08TPTDBA','erp_id'=>391762755,'erp_sku'=>'C08TPT','title'=>'Fercar Carrinho para Ferramentas C08 Grande Preto'],
 ['seller_sku'=>'1C7Q-LKUT-WWJG','erp_id'=>353735815,'erp_sku'=>'C08BR','title'=>'Carrinho Fechado Branco com 6 Gavetas-FERCAR-C08BR'],
 ['seller_sku'=>'1C7Q-LKVG-DZE9','erp_id'=>388941857,'erp_sku'=>'C06TPT','title'=>'Carrinho de Ferramentas Fercar com 3 Gavetas'],
 ['seller_sku'=>'1C7Q-LKWG-IFOH','erp_id'=>353736291,'erp_sku'=>'C08VM','title'=>'Carrinho para Ferramentas com 6 Gavetas Vermelho'],
 ['seller_sku'=>'1C7Q-LKWT-GPYR','erp_id'=>373539622,'erp_sku'=>'C06CZ','title'=>'Fercar Carrinho Fechado para Ferramentas com 03 Gavetas'],
 ['seller_sku'=>'C08TPTONSITE','erp_id'=>391762755,'erp_sku'=>'C08TPT','title'=>'Fercar Carrinho para Ferramentas C08 Grande Preto'],
 ['seller_sku'=>'C08TVMDBA','erp_id'=>353735022,'erp_sku'=>'C08TVM','title'=>'Fercar Carrinho para Ferramentas C08 Grande Vermelho'],
 ['seller_sku'=>'C08TVMONSITE','erp_id'=>353735022,'erp_sku'=>'C08TVM','title'=>'Fercar Carrinho para Ferramentas C08 Grande Vermelho'],
 ['seller_sku'=>'LN-C6RF-O1VB','erp_id'=>353736291,'erp_sku'=>'C08VM','title'=>'Carrinho para Ferramentas com 6 Gavetas Vermelho e Cinza'],
];
foreach($toolCarts as &$cart){
    $cart['amazon_status']=['BUYABLE'];$cart['program']='FBA_ONSITE';
    $cart['price']=1549.00;$cart['last_cost']=1000.00;
    $cart['current_margin_cost_used']=1000.00;$cart['current_margin_bps']=774;
    $cart['current_commission_cents']=23235;$cart['current_logistics_cents']=1000;
    $cart['current_billable_kg']=1.3;
}
unset($cart);
$toolSkus=array_column($toolCarts,'seller_sku');
$toolPlan=SvAmazonTargetMarginPricePlan::prepare(
    ['rows'=>$toolCarts],$toolSkus,900,'other');
assertTarget($toolPlan['selected']===10&&$toolPlan['excluded']===10
    &&$toolPlan['simulated']===0&&$toolPlan['pending']===0,
    'all_ten_tool_cart_offers_excluded');
foreach($toolPlan['rows'] as $cartRow){
    assertTarget($cartRow['state']==='excluded'
        &&$cartRow['reason']==='carrinho_ferramentas_excecao'
        &&$cartRow['proposed_price_cents']===null
        &&$cartRow['minimum_price_cents']===null,
        'tool_cart_has_no_sale_minimum_or_maximum_proposal');
}
$futureCart=$toolCarts[0];
$futureCart['seller_sku']='NEW-TOOL-CART';
$futureCart['erp_id']=999001;$futureCart['erp_sku']='NEW-CART';
$futureCart['title']='Carrinho para Ferramentas Oficina Modelo Novo';
$futurePlan=SvAmazonTargetMarginPricePlan::prepare(
    ['rows'=>[$futureCart]],['NEW-TOOL-CART'],900,'other');
assertTarget($futurePlan['excluded']===1
    &&$futurePlan['rows'][0]['state']==='excluded',
    'future_tool_cart_title_pattern_excluded');
$armory=$sku;$armory['seller_sku']='ARMORY-08';
$armory['erp_id']=342715215;$armory['erp_sku']='ARM-08';
$armory['title']='Armário em Aço com 1 Prateleira ARM-08 Ferramix';
$armoryPlan=SvAmazonTargetMarginPricePlan::prepare(
    ['rows'=>[$armory]],['ARMORY-08'],900,'other');
assertTarget($armoryPlan['excluded']===0&&$armoryPlan['simulated']===1,
    'armory_is_not_misclassified_as_tool_cart');
assertTarget($toolPlan['cart_exception_rule']==='exclude_tool_carts_by_current_erp_identity_and_title',
    'cart_exception_rule_is_explicit_in_preview');

echo "PASS Amazon target-margin price simulation: cents, tariffs, ERP 991 groups, no writes, projections, missing data\n";
