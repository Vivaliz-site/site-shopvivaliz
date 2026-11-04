<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/PriorCostReference.php';
$cur=['seller_sku'=>'1C7Q-LKUB-1A9M','asin'=>'B076Y8BT55','ean'=>'7894162000168',
      'status'=>'erp_or_catalog_read_failed','last_cost'=>null,'margin_weighted_bps'=>null,'erp_id'=>null];
$old=['seller_sku'=>'1C7Q-LKUB-1A9M','asin'=>'B076Y8BT55','ean'=>'7894162000168',
      'status'=>'no_confirmed_sales','erp_id'=>341682112,'erp_sku'=>'6',
      'last_cost'=>83,'last_cost_date'=>'2026-09-17',
      'last_cost_source'=>'OLIST_TINY_V3_PRODUCT_COST_HISTORY'];
$ref=SvAmazonMarginPriorCostReference::apply($cur,$old,3,'2026-11-04 00:55:12','2026-11-04 00:23:26');
if (($ref['prior_cost_reference']['cost']??null)!==83.0
    || ($ref['prior_cost_reference']['verified_run_id']??null)!==3
    || $ref['last_cost']!==null || $ref['margin_weighted_bps']!==null
    || $ref['status']!=='erp_or_catalog_read_failed')throw new RuntimeException('verified reference may not become certified cost/margin');
$bad=$old;$bad['ean']='7899999999999';
if(isset(SvAmazonMarginPriorCostReference::apply($cur,$bad,3,'2026-11-04 00:55:12','2026-11-04 00:23:26')['prior_cost_reference']))throw new RuntimeException('GTIN drift must block fallback');
$bad=$old;$bad['asin']='B00WRONG';
if(isset(SvAmazonMarginPriorCostReference::apply($cur,$bad,3,'2026-11-04 00:55:12','2026-11-04 00:23:26')['prior_cost_reference']))throw new RuntimeException('ASIN drift must block fallback');
$bad=$old;$bad['last_cost_source']='UNVERIFIED';
if(isset(SvAmazonMarginPriorCostReference::apply($cur,$bad,3,'2026-11-04 00:55:12','2026-11-04 00:23:26')['prior_cost_reference']))throw new RuntimeException('unverified cost cannot become reference');
if(isset(SvAmazonMarginPriorCostReference::apply($cur,$old,3,'2026-11-04 00:55:12','2026-11-01 00:20:12')['prior_cost_reference']))throw new RuntimeException('old reference must expire');
$full=SvAmazonMarginPriorCostReference::augment(['run_id'=>4,'generated_at'=>'2026-11-04 00:55:12','snapshot'=>['rows'=>[$cur]]],
    [['run_id'=>3,'generated_at'=>'2026-11-04 00:23:26','snapshot'=>['rows'=>[$old]]]]);
if(!isset($full['snapshot']['rows'][0]['prior_cost_reference']))throw new RuntimeException('augment failed');
echo "PASS cost provenance: verified previous snapshot, identity fence, source/date freshness, no false margin\n";
