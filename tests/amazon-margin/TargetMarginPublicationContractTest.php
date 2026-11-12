<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/TargetMarginPublication.php';

function tpub(bool $ok,string $name):void {if(!$ok)throw new RuntimeException('target_margin_publication_'.$name);}
$table=[
 'id'=>991,'descricao'=>'Amazon','acrescimoDesconto'=>10.0,
 'excecoes'=>[
  ['idProduto'=>101,'codigo'=>'OLIST-101','preco'=>130.50,'precoPromocional'=>0.0,
   'other_metadata'=>'preserve_this'],
  ['idProduto'=>102,'codigo'=>'OLIST-102','preco'=>299.99,'precoPromocional'=>0.0],
 ]
];
$before=json_encode($table,JSON_THROW_ON_ERROR);
$planned=SvAmazonTargetMarginPublication::olistPriceListPayload($table,101,'OLIST-101',15732);
tpub($planned['descricao']==='Amazon' && $planned['acrescimoDesconto']===10.0,'list_991_meta');
tpub($planned['excecoes'][0]['preco']===157.32
    && $planned['excecoes'][0]['other_metadata']==='preserve_this'
    && $planned['excecoes'][1]===$table['excecoes'][1],'preserve_other_exceptions_and_metadata');
tpub(json_encode($table,JSON_THROW_ON_ERROR)===$before,'no_inplace_mutation');
tpub(SvAmazonTargetMarginPublication::olistPriceVerified(
    $table,array_merge(['id'=>991],$planned),101,15732),'list_readback_exact');
$corrupted=$planned;$corrupted['excecoes'][1]['preco']=299.00;
tpub(!SvAmazonTargetMarginPublication::olistPriceVerified(
    $table,array_merge(['id'=>991],$corrupted),101,15732),'no_unrelated_exception_overwrite');
$insert=SvAmazonTargetMarginPublication::olistPriceListPayload($table,103,'OLIST-103',4401);
tpub(count($insert['excecoes'])===3
    && $insert['excecoes'][2]['preco']===44.01
    && $insert['excecoes'][2]['idProduto']===103,'new_exception_id_exact');
$promo=$table;$promo['excecoes'][0]['precoPromocional']=99.90;
try{SvAmazonTargetMarginPublication::olistPriceListPayload($promo,101,'OLIST-101',15732);
    throw new RuntimeException('bad promo accepted');
}catch(RuntimeException $e){tpub($e->getMessage()==='price_table_promotion_requires_reconciliation',
    'preserve_active_promotion');}
$patch=SvAmazonTargetMarginPublication::amazonPricePatch('CHAIR','A2Q3Y263D00KWC',15732);
tpub($patch['productType']==='CHAIR','listing_product_type_preserved');
tpub(count($patch['patches'])===1
    && $patch['patches'][0]['op']==='merge'
    && $patch['patches'][0]['path']==='/attributes/purchasable_offer','granular_merge_not_replace');
$offer=$patch['patches'][0]['value'][0];
tpub($offer['currency']==='BRL'&&$offer['marketplace_id']==='A2Q3Y263D00KWC'
    && $offer['our_price'][0]['schedule'][0]['value_with_tax']===157.32
    && $offer['minimum_seller_allowed_price'][0]['schedule'][0]['value_with_tax']===157.32,
    'sale_and_floor_together');
tpub(count($offer)===5,'no_extra_commerce_fields');
$listing=['attributes'=>['purchasable_offer'=>[$offer]]];
tpub(SvAmazonTargetMarginPublication::listingPricesMatch($listing,'A2Q3Y263D00KWC',15732),
    'both_prices_verified');
$badListing=$listing;$badListing['attributes']['purchasable_offer'][0]
    ['minimum_seller_allowed_price'][0]['schedule'][0]['value_with_tax']=150.0;
tpub(!SvAmazonTargetMarginPublication::listingPricesMatch(
    $badListing,'A2Q3Y263D00KWC',15732),'minimum_mismatch_not_certified');
$row=['seller_sku'=>'TEST-001','program'=>'FBA_ONSITE','amazon_status'=>['BUYABLE'],
    'last_cost'=>50.00,'amazon_package'=>['weight_kg'=>0.4,
    'length_cm'=>10,'width_cm'=>10,'height_cm'=>10]];
$quote=['price_cents'=>15732,'commission_cents'=>2359];
$margin=SvAmazonTargetMarginPublication::quotedMargin($row,15732,$quote,1400,'other');
tpub($margin['current_fee_source']==='AMAZON_SP_API_PRODUCT_FEES_CURRENT_PRICE'
    && $margin['current_margin_bps']!==null,'official_fee_quote_at_new_price');
try{SvAmazonTargetMarginPublication::quotedMargin($row,15733,$quote,1400,'other');
 throw new RuntimeException('accepted different quotation price');
}catch(InvalidArgumentException $e){tpub($e->getMessage()==='official_quote_at_price_missing',
 'official_quote_price_must_match');}
echo "PASS target margin Olist 991 preserved, Amazon MERGE sale+floor, readback and fee-at-proposed-price\n";
