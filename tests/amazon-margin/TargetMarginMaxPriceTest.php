<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/TargetMarginPublication.php';

function maxPriceAssert(bool $ok,string $case):void {
    if(!$ok)throw new RuntimeException('amazon_maximum_price_'.$case);
}
$marketplace='A2Q3Y263D00KWC';
$saleCents=18348;
$allOffer=[
    'currency'=>'BRL','audience'=>'ALL','marketplace_id'=>$marketplace,
    'our_price'=>[['schedule'=>[['value_with_tax'=>159.00]]]],
    'minimum_seller_allowed_price'=>[['schedule'=>[['value_with_tax'=>159.00]]]],
    'maximum_seller_allowed_price'=>[['schedule'=>[['value_with_tax'=>159.00]]]],
];
$b2bOffer=[
    'currency'=>'BRL','audience'=>'B2B','marketplace_id'=>$marketplace,
    'our_price'=>[['schedule'=>[['value_with_tax'=>200.00]]]],
    'minimum_seller_allowed_price'=>[['schedule'=>[['value_with_tax'=>120.00]]]],
    'maximum_seller_allowed_price'=>[['schedule'=>[['value_with_tax'=>250.00]]]],
];
$listing=['attributes'=>['purchasable_offer'=>[$allOffer,$b2bOffer]]];
$before=json_encode($listing,JSON_THROW_ON_ERROR);
try {
    $patch=SvAmazonTargetMarginPublication::amazonPricePatch(
        'CHAIR',$marketplace,$saleCents,$listing);
} catch(Throwable $e) {
    throw new RuntimeException('amazon_maximum_price_raise_when_sale_exceeds_existing',0,$e);
}
$offers=$patch['patches'][0]['value'];
maxPriceAssert($offers[0]['our_price'][0]['schedule'][0]['value_with_tax']===183.48,
    'sale_price_is_target');
maxPriceAssert($offers[0]['minimum_seller_allowed_price'][0]['schedule'][0]['value_with_tax']===183.48,
    'minimum_equals_sale_price');
maxPriceAssert($offers[0]['maximum_seller_allowed_price'][0]['schedule'][0]['value_with_tax']===201.83,
    'maximum_is_at_least_ten_percent_above_sale');
maxPriceAssert($offers[1]===$b2bOffer,'unrelated_b2b_offer_preserved');
maxPriceAssert(json_encode($listing,JSON_THROW_ON_ERROR)===$before,'original_listing_not_mutated');

$readback=['attributes'=>['purchasable_offer'=>$offers]];
maxPriceAssert(SvAmazonTargetMarginPublication::listingPricesMatch(
    $readback,$marketplace,$saleCents,20183),'readback_sale_minimum_and_maximum_match');
$badReadback=$readback;
$badReadback['attributes']['purchasable_offer'][0]
    ['maximum_seller_allowed_price'][0]['schedule'][0]['value_with_tax']=200.00;
maxPriceAssert(!SvAmazonTargetMarginPublication::listingPricesMatch(
    $badReadback,$marketplace,$saleCents,20183),'readback_rejects_wrong_maximum');

$higher=$listing;
$higher['attributes']['purchasable_offer'][0]
    ['maximum_seller_allowed_price'][0]['schedule'][0]['value_with_tax']=220.00;
$preserved=SvAmazonTargetMarginPublication::amazonPricePatch(
    'CHAIR',$marketplace,$saleCents,$higher);
maxPriceAssert($preserved['patches'][0]['value'][0]
    ['maximum_seller_allowed_price'][0]['schedule'][0]['value_with_tax']===220.00,
    'existing_maximum_above_sale_is_preserved');

$noMaximum=['attributes'=>['purchasable_offer'=>[[
    'currency'=>'BRL','audience'=>'ALL','marketplace_id'=>$marketplace,
    'our_price'=>[['schedule'=>[['value_with_tax'=>100.00]]]],
    'minimum_seller_allowed_price'=>[['schedule'=>[['value_with_tax'=>100.00]]]],
]]]];
$noMaximumPatch=SvAmazonTargetMarginPublication::amazonPricePatch(
    'CHAIR',$marketplace,$saleCents,$noMaximum);
maxPriceAssert(!array_key_exists('maximum_seller_allowed_price',
    $noMaximumPatch['patches'][0]['value'][0]),'missing_maximum_is_not_invented');
maxPriceAssert(SvAmazonTargetMarginPublication::targetMaximumSellerAllowedPriceCents(
    $listing,$marketplace,$saleCents)===20183,'expected_maximum_persisted_from_fresh_listing');
maxPriceAssert(SvAmazonTargetMarginPublication::targetMaximumSellerAllowedPriceCents(
    $higher,$marketplace,$saleCents)===22000,'existing_higher_maximum_persisted');
maxPriceAssert(SvAmazonTargetMarginPublication::targetMaximumSellerAllowedPriceCents(
    $noMaximum,$marketplace,$saleCents)===null,'missing_maximum_checkpoint_is_null');

$rounding=$noMaximum;
$rounding['attributes']['purchasable_offer'][0]['maximum_seller_allowed_price']=[
    ['schedule'=>[['value_with_tax'=>100.00]]],
];
$roundedPatch=SvAmazonTargetMarginPublication::amazonPricePatch(
    'CHAIR',$marketplace,10001,$rounding);
maxPriceAssert($roundedPatch['patches'][0]['value'][0]
    ['maximum_seller_allowed_price'][0]['schedule'][0]['value_with_tax']===110.02,
    'maximum_rounds_up_to_never_fall_below_ten_percent');

$scheduled=$listing;
$scheduled['attributes']['purchasable_offer'][0]
    ['maximum_seller_allowed_price'][0]['schedule'][0]['start_at']='2026-11-15T00:00:00Z';
try{
    SvAmazonTargetMarginPublication::amazonPricePatch(
        'CHAIR',$marketplace,$saleCents,$scheduled);
    throw new RuntimeException('scheduled_maximum_accepted');
}catch(RuntimeException $e){
    maxPriceAssert($e->getMessage()==='amazon_scheduled_maximum_price_requires_reconciliation',
        'scheduled_maximum_requires_reconciliation');
}
$belowReadback=$readback;
$belowReadback['attributes']['purchasable_offer'][0]
    ['maximum_seller_allowed_price'][0]['schedule'][0]['value_with_tax']=159.00;
maxPriceAssert(!SvAmazonTargetMarginPublication::listingPricesMatch(
    $belowReadback,$marketplace,$saleCents),'legacy_readback_rejects_maximum_below_sale');

echo "PASS Amazon sale/minimum parity, maximum +10%, preserve higher maximum, B2B and readback\n";
