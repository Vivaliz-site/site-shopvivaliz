<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/PromoFloorGuard.php';
function check(string $name, array $p, string $expect):void {
 $a=SvAmazonPromoFloorGuard::evaluate($p);
 if(($a['status']??'')!==$expect)throw new RuntimeException($name.' expected '.$expect.' got '.json_encode($a));
}
$o=['asin'=>'ASIN1','seller_sku'=>'SKU1','buyable'=>true,'identity_verified'=>true,
 'kit_cost_verified'=>true,'logistics_verified'=>true,'tax_verified'=>true,
 'price_cents'=>4500,'live_price_cents'=>4500,'full_kit_cost_cents'=>1494,
 'commission_current_cents'=>675,'commission_promo_cents'=>641,
 'logistics_current_cents'=>650,'logistics_promo_cents'=>650,'tax_guard_cents'=>630,
 'other_costs_cents'=>0,'fee_quote_price_cents'=>4275];
$p=['asin'=>'ASIN1','coupon_bps'=>500,'stacking_discount_bps'=>0,
 'coupon_redemption_fee_cents'=>100,'ads_cpa_cents'=>100,
 'stacking_verified'=>true,'ads_attribution_verified'=>true,
 'all_buyable_seller_skus'=>['SKU1'],'offers'=>[$o]];
check('passes real conservative example',$p,'PASS_FINANCIAL_ONLY');
$ok=SvAmazonPromoFloorGuard::evaluate($p);
if($ok['offers']['SKU1']['net_profit_cents']!==626 || $ok['offers']['SKU1']['net_price_cents']!==4275)
 throw new RuntimeException('precise cents expected for all costs, including coupon');
check('deterministic repeat',$p,'PASS_FINANCIAL_ONLY');
$q=$p;unset($q['coupon_redemption_fee_cents']);check('unknown coupon redemption fee',$q,'HOLD_EVIDENCE');
$q=$p;$q['stacking_verified']=false;check('unverified stacking',$q,'HOLD_EVIDENCE');
$q=$p;$q['all_buyable_seller_skus']=['SKU1','SKU2'];check('missing ASIN sibling',$q,'HOLD_EVIDENCE');
$q=$p;$q['offers'][0]['live_price_cents']=4400;check('live price changed',$q,'HOLD_EVIDENCE');
$q=$p;$q['offers'][0]['kit_cost_verified']=false;check('kit not verified',$q,'HOLD_EVIDENCE');
$q=$p;$q['offers'][0]['fee_quote_price_cents']=4400;check('fee quote wrong discount',$q,'HOLD_EVIDENCE');
$q=$p;$q['ads_cpa_cents']=300;check('extra PPC spend eats margin',$q,'BLOCK_BELOW_10_PERCENT');
$q=$p;$q['stacking_discount_bps']=1000;$q['offers'][0]['fee_quote_price_cents']=3847;
check('coupon plus 10 percent stack',$q,'BLOCK_BELOW_10_PERCENT');
$q=$p;$o2=$o;$o2['seller_sku']='SKU2';$o2['price_cents']=2500;$o2['live_price_cents']=2500;
$o2['full_kit_cost_cents']=1000;$o2['commission_current_cents']=275;$o2['commission_promo_cents']=261;
$o2['logistics_current_cents']=625;$o2['logistics_promo_cents']=625;$o2['tax_guard_cents']=350;
$o2['fee_quote_price_cents']=2375;
$q['offers'][]=$o2;$q['all_buyable_seller_skus'][]='SKU2';
check('ASIN sibling below minimum',$q,'BLOCK_BELOW_10_PERCENT');
$q=$p;$q['offers'][]=$o;$q['all_buyable_seller_skus'][]='SKU1';check('duplicate seller SKU',$q,'HOLD_EVIDENCE');
echo 'PASS PromoFloorGuard: BRL cents, all sibling SKUs, cost completeness, quote price, stacking, ad spend, 10pct floor, deterministic\n';
