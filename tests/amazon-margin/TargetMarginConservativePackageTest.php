<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/CurrentPriceMargin.php';
require_once __DIR__.'/../../includes/amazon-margin/TargetMarginPublication.php';
function assertEnvelope(bool $ok, string $case):void {
    if (!$ok) throw new RuntimeException('conservative_9pct_'.$case);
}
$base=[
    'seller_sku'=>'ARM8DBA','program'=>'DBA','amazon_status'=>['BUYABLE'],
    'last_cost'=>268.27, 'current_package_conflict'=>true,
    'amazon_package'=>['weight_kg'=>7.7,'length_cm'=>30,'width_cm'=>60,'height_cm'=>63],
    'erp_package'=>['weight_kg'=>8.99,'length_cm'=>64,'width_cm'=>60,'height_cm'=>30],
];
$q=['price_cents'=>56094,'commission_cents'=>6170];
$m=SvAmazonTargetMarginPublication::quotedMargin($base,56094,$q,1400,'other');
assertEnvelope($m['current_logistics_cents']===10595,'arm8_uses_higher_official_logistics');
assertEnvelope($m['current_profit_cents']===4649,'arm8_cannot_claim_9pct_at_underpriced_candidate');
assertEnvelope($m['current_margin_bps']<900,'arm8_price_must_increase');
assertEnvelope(($m['conservative_package_envelope']??false)===true,'arm8_has_provenance');
$japi=$base;
$japi['seller_sku']='1C7Q-LKX6-27AL';$japi['last_cost']=160;
$japi['amazon_package']=['weight_kg'=>2.2,'length_cm'=>72,'width_cm'=>30,'height_cm'=>25];
$japi['erp_package']=['weight_kg'=>1.9,'length_cm'=>72,'width_cm'=>30,'height_cm'=>25];
$m=SvAmazonTargetMarginPublication::quotedMargin($japi,34762,
    ['price_cents'=>34762,'commission_cents'=>4171],1400,'other');
assertEnvelope($m['current_logistics_cents']===6595,'same_tariff_does_not_false_block');
assertEnvelope($m['current_profit_cents']===3129,'same_tariff_remains_at_9pct');
$pet=$base;$pet['seller_sku']='PET/CC2*AZP';$pet['last_cost']=70;
$pet['amazon_package']=['weight_kg'=>5,'length_cm'=>36,'width_cm'=>48,'height_cm'=>36];
$pet['erp_package']=['weight_kg'=>1.5,'length_cm'=>45,'width_cm'=>58,'height_cm'=>45];
$m=SvAmazonTargetMarginPublication::quotedMargin($pet,21530,
    ['price_cents'=>21530,'commission_cents'=>2583],1400,'other');
assertEnvelope($m['current_logistics_cents']===10595,'pet_takes_worst_billable_dimension');
assertEnvelope($m['current_profit_cents']===-1662,'pet_old_9pct_candidate_rejected');
$unknown=$base;unset($unknown['erp_package']);
try {
    SvAmazonTargetMarginPublication::quotedMargin($unknown,56094,$q,1400,'other');
    throw new RuntimeException('accepted_unverified_package');
} catch (RuntimeException $e) {
    assertEnvelope($e->getMessage()==='package_conflict_unresolved','unknown_still_requires_review');
}

// The owner's signed preview must already incorporate the upper known tariff;
// otherwise the P2 worker could increase the price materially after approval.
require_once __DIR__.'/../../includes/amazon-margin/TargetMarginPricePlan.php';
$preview=$base;
$preview['price']=520.39;
$preview['current_commission_cents']=5724;
$preview['current_margin_bps']=386;
$preview['current_billable_kg']=18.92;
$preview['erp_id']=342715215;
$preview['erp_sku']='ARM-08';
$preview['_target_tax_bps']=1400;
$plan=SvAmazonTargetMarginPricePlan::prepare(
    ['rows'=>[$preview]],['ARM8DBA'],900,'other');
$planned=$plan['rows'][0];
assertEnvelope($planned['state']==='simulated','preview_arm8_simulated');
assertEnvelope($planned['logistics_cents']===10595,'preview_arm8_upper_tariff');
assertEnvelope($planned['profit_estimate_cents']*10000 >= 900*$planned['proposed_price_cents'],
    'preview_arm8_target_9pct_in_cents');
assertEnvelope($planned['proposed_price_cents']>56094,
    'preview_arm8_not_understated');
assertEnvelope(in_array('tarifa_conservadora_entre_embalagens_amazon_olist',
    $planned['notices'],true),'preview_arm8_upper_tariff_disclosed');
$petPreview=$pet;
$petPreview['price']=215.10;
$petPreview['current_commission_cents']=2581;
$petPreview['current_margin_bps']=894;
$petPreview['current_billable_kg']=10.388;
$petPreview['erp_id']=342374752;
$petPreview['erp_sku']='ASTRAPET-CASINHA-CACHORRO-AZUL';
$petPreview['_target_tax_bps']=1400;
$plan=SvAmazonTargetMarginPricePlan::prepare(
    ['rows'=>[$petPreview]],['PET/CC2*AZP'],900,'other');
$planned=$plan['rows'][0];
assertEnvelope($planned['state']==='simulated','preview_pet_simulated');
assertEnvelope($planned['logistics_cents']===10595,'preview_pet_upper_tariff');
assertEnvelope($planned['proposed_price_cents']>21530,'preview_pet_discloses_real_increase');
assertEnvelope($planned['profit_estimate_cents']*10000 >= 900*$planned['proposed_price_cents'],
    'preview_pet_target_9pct_in_cents');


// A 20 g package delta is below the display-conflict tolerance, but it
// crosses the 0.5 kg official DBA tariff band. The upper tariff must still
// be used for both the signed preview and the exact SP-API quote.
$nearBoundary=$base;
$nearBoundary['seller_sku']='DBA-NEAR-TARIFF-BOUNDARY';
$nearBoundary['last_cost']=50.00;
$nearBoundary['current_package_conflict']=false;
$nearBoundary['amazon_package']=[
    'weight_kg'=>0.47,'length_cm'=>10,'width_cm'=>10,'height_cm'=>10,
];
$nearBoundary['erp_package']=[
    'weight_kg'=>0.49,'length_cm'=>10,'width_cm'=>10,'height_cm'=>10,
];
$amazonBillable=SvAmazonCurrentPriceMargin::billable($nearBoundary['amazon_package']);
$erpBillable=SvAmazonCurrentPriceMargin::billable($nearBoundary['erp_package']);
assertEnvelope($amazonBillable['billable_kg']===0.49
    &&$erpBillable['billable_kg']===0.51,'sub_tolerance_packages_straddle_half_kg');
$amazonFee=SvAmazonCurrentPriceMargin::logisticsCents(
    'DBA',12000,$amazonBillable['billable_kg'],'other');
$erpFee=SvAmazonCurrentPriceMargin::logisticsCents(
    'DBA',12000,$erpBillable['billable_kg'],'other');
assertEnvelope($amazonFee!==$erpFee,'boundary_changes_official_tariff');
$boundaryMargin=SvAmazonTargetMarginPublication::quotedMargin(
    $nearBoundary,12000,['price_cents'=>12000,'commission_cents'=>1800],1400,'other');
assertEnvelope($boundaryMargin['current_logistics_cents']===max($amazonFee,$erpFee),
    'sub_tolerance_quote_uses_upper_tariff');
$nearBoundary['price']=120.00;
$nearBoundary['current_commission_cents']=1800;
$nearBoundary['current_margin_bps']=1504;
$nearBoundary['current_billable_kg']=$amazonBillable['billable_kg'];
$nearBoundary['erp_id']=99001;
$nearBoundary['erp_sku']='DBA-NEAR';
$nearBoundary['_target_tax_bps']=1400;
$boundaryPlan=SvAmazonTargetMarginPricePlan::prepare(
    ['rows'=>[$nearBoundary]],['DBA-NEAR-TARIFF-BOUNDARY'],900,'other');
$boundaryRow=$boundaryPlan['rows'][0];
assertEnvelope($boundaryRow['state']==='simulated','sub_tolerance_preview_simulated');
assertEnvelope($boundaryRow['logistics_cents']===max($amazonFee,$erpFee),
    'sub_tolerance_preview_uses_upper_tariff');
assertEnvelope(in_array('tarifa_conservadora_entre_embalagens_amazon_olist',
    $boundaryRow['notices'],true),'sub_tolerance_preview_discloses_envelope');

// Amazon catalog package outside program limits (height 90 cm) + valid single-unit
// ERP package: the ERP package is authoritative for the tariff.
$overweightAmz=$base;
$overweightAmz['seller_sku']='JVAQCQ55';
$overweightAmz['program']='FBA_ONSITE';
$overweightAmz['last_cost']=148.00;
$overweightAmz['kit_cost_multiplier']=1;
$overweightAmz['amazon_package']=['weight_kg'=>3.3,'length_cm'=>55,'width_cm'=>55,'height_cm'=>90];
$overweightAmz['erp_package']=['weight_kg'=>3.3,'length_cm'=>55,'width_cm'=>55,'height_cm'=>52];
$overweightMargin=SvAmazonTargetMarginPublication::quotedMargin(
    $overweightAmz,38500,['price_cents'=>38500,'commission_cents'=>5775],1400,null);
assertEnvelope(($overweightMargin['erp_package_override_ineligible_amazon']??false)===true,
    'overweight_amazon_uses_valid_erp_package');
assertEnvelope($overweightMargin['current_package_source']==='OLIST_V3_REGISTERED_PACKAGE',
    'overweight_amazon_package_source_is_erp');
$multiKit=$overweightAmz;$multiKit['kit_cost_multiplier']=2;
$blocked=false;
try{SvAmazonTargetMarginPublication::quotedMargin(
    $multiKit,38500,['price_cents'=>38500,'commission_cents'=>5775],1400,null);}
catch(RuntimeException $e){$blocked=true;} // fail-closed, whichever guard trips first
assertEnvelope($blocked,'multi_unit_kit_never_uses_single_unit_erp_package');

$pkgPatch=SvAmazonTargetMarginPublication::amazonPackagePatch(
    'PLANTER','A2Q3Y263D00KWC',$overweightAmz['erp_package'],[
        'attributes'=>[
            'item_package_dimensions'=>[['marketplace_id'=>'A2Q3Y263D00KWC',
                'length'=>['unit'=>'centimeters','value'=>55],
                'width'=>['unit'=>'centimeters','value'=>55],
                'height'=>['unit'=>'centimeters','value'=>90]]],
            'item_package_weight'=>[['marketplace_id'=>'A2Q3Y263D00KWC',
                'unit'=>'kilograms','value'=>3.3]],
        ],
    ]);
assertEnvelope($pkgPatch['productType']==='PLANTER'
    && count($pkgPatch['patches'])===2
    && $pkgPatch['patches'][0]['value'][0]['height']['value']===52.0
    && $pkgPatch['patches'][1]['value'][0]['value']===3.3,
    'amazon_package_patch_builds_valid_spapi_payload');
$updatedListing=['attributes'=>[
    'item_package_dimensions'=>$pkgPatch['patches'][0]['value'],
    'item_package_weight'=>$pkgPatch['patches'][1]['value'],
]];
assertEnvelope(SvAmazonTargetMarginPublication::listingPackageMatches(
    $updatedListing,$overweightAmz['erp_package'])===true,'listing_package_matches_verifies_readback');

echo "PASS conservative dimensions quoted margin: worst tariff, same tariff, ERP override for out-of-limit Amazon package, SP-API package patch, unknown blocked\n";
