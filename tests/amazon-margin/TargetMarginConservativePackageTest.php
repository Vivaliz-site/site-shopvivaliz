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
echo "PASS conservative dimensions quoted margin: worst tariff, same tariff, unknown blocked\n";
