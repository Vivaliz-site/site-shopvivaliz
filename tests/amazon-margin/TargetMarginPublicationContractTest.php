<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/TargetMarginPublication.php';

function tpub(bool $ok,string $name):void {if(!$ok)throw new RuntimeException('target_margin_publication_'.$name);}
$table=[
 'id'=>991,'descricao'=>'Amazon','acrescimoDesconto'=>10.0,
 'excecoes'=>[
  ['idProduto'=>101,'codigo'=>'OLIST-101','preco'=>130.50,'precoPromocional'=>0.0,
   'other_metadata'=>'preserve_this'],
  ['idProduto'=>102,'codigo'=>'OLIST-102','preco'=>299.99,'precoPromocional'=>280.0],
 ]
];
$before=json_encode($table,JSON_THROW_ON_ERROR);
$planned=SvAmazonTargetMarginPublication::olistPriceListPayload($table,101,'OLIST-101',15732);
tpub($planned['descricao']==='Amazon' && $planned['acrescimoDesconto']===10.0,'list_991_meta');
tpub(isset($planned['itens'])&&!isset($planned['excecoes'])
    &&count($planned['itens'])===2,'official_put_uses_itens_not_get_excecoes');
tpub($planned['itens'][0]===['idProduto'=>101,'preco'=>157.32,'precoPromocional'=>0.0]
    &&$planned['itens'][1]===['idProduto'=>102,'preco'=>299.99,'precoPromocional'=>280.0],
    'official_put_schema_and_preserve_unrelated_values');
tpub(!array_key_exists('codigo',$planned['itens'][0])
    &&!array_key_exists('other_metadata',$planned['itens'][0]),
    'only_official_put_fields_are_sent');
tpub(json_encode($table,JSON_THROW_ON_ERROR)===$before,'no_inplace_mutation');
$after=$table;$after['excecoes'][0]['preco']=157.32;
tpub(SvAmazonTargetMarginPublication::olistPriceVerified(
    $table,$after,101,15732),'list_readback_exact');
$corrupted=$after;$corrupted['excecoes'][1]['preco']=299.00;
tpub(!SvAmazonTargetMarginPublication::olistPriceVerified(
    $table,$corrupted,101,15732),'no_unrelated_exception_overwrite');
$insert=SvAmazonTargetMarginPublication::olistPriceListPayload($table,103,'OLIST-103',4401);
tpub(count($insert['itens'])===3
    &&$insert['itens'][2]['preco']===44.01
    &&$insert['itens'][2]['idProduto']===103
    &&$insert['itens'][2]['precoPromocional']===0.0,
    'new_exception_uses_official_item_schema');
$restore=SvAmazonTargetMarginPublication::olistPriceListRestorePayload($table);
tpub($restore['descricao']==='Amazon'&&$restore['acrescimoDesconto']===10.0
    &&$restore['itens'][0]['preco']===130.50&&$restore['itens'][1]['preco']===299.99
    &&$restore['itens'][1]['precoPromocional']===280.0,
    'compensation_uses_same_official_put_schema');
$promo=$table;$promo['excecoes'][0]['precoPromocional']=99.90;
try{SvAmazonTargetMarginPublication::olistPriceListPayload($promo,101,'OLIST-101',15732);
    throw new RuntimeException('bad promo accepted');
}catch(RuntimeException $e){tpub($e->getMessage()==='price_table_promotion_requires_reconciliation',
    'preserve_active_promotion');}
$patch=SvAmazonTargetMarginPublication::amazonPricePatch('CHAIR','A2Q3Y263D00KWC',15732);
tpub($patch['productType']==='CHAIR','listing_product_type_preserved');
tpub(count($patch['patches'])===1
    && $patch['patches'][0]['op']==='replace'
    && $patch['patches'][0]['path']==='/attributes/purchasable_offer','official_replace_only_purchasable_offer');
$offer=$patch['patches'][0]['value'][0];
tpub($offer['currency']==='BRL'&&$offer['marketplace_id']==='A2Q3Y263D00KWC'
    && $offer['our_price'][0]['schedule'][0]['value_with_tax']===157.32
    && $offer['minimum_seller_allowed_price'][0]['schedule'][0]['value_with_tax']===157.32,
    'sale_and_floor_together');
tpub(count($offer)===5,'no_extra_commerce_fields');
$listing=['attributes'=>['purchasable_offer'=>[$offer]]];
$unrelatedOffer=['currency'=>'BRL','audience'=>'B2B',
    'marketplace_id'=>'A2Q3Y263D00KWC',
    'our_price'=>[['schedule'=>[['value_with_tax'=>200.00]]]],
    'minimum_seller_allowed_price'=>[['schedule'=>[['value_with_tax'=>120.00]]]]];
$originalLiveListing=['attributes'=>[
    'purchasable_offer'=>[$offer,$unrelatedOffer],
    'item_name'=>[['value'=>'Unrelated product title','language_tag'=>'pt_BR']]]];
$originalSerialized=json_encode($originalLiveListing,JSON_THROW_ON_ERROR);
$replaced=SvAmazonTargetMarginPublication::amazonPricePatch(
    'CHAIR','A2Q3Y263D00KWC',16999,$originalLiveListing);
tpub($replaced['patches'][0]['op']==='replace'
    &&count($replaced['patches'])===1
    &&count($replaced['patches'][0]['value'])===2
    &&$replaced['patches'][0]['value'][1]===$unrelatedOffer
    &&$replaced['patches'][0]['value'][0]['our_price'][0]['schedule'][0]['value_with_tax']===169.99,
    'replace_existing_live_attribute_preserves_all_other_audiences');
tpub(json_encode($originalLiveListing,JSON_THROW_ON_ERROR)===$originalSerialized
    &&!isset($replaced['patches'][0]['value'][0]['item_name']),
    'no_mutation_of_original_listing_and_no_unrelated_attribute_in_patch');
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
echo "PASS target margin Olist 991 preserved, Amazon REPLACE offer sale+floor, readback and fee-at-proposed-price\n";
