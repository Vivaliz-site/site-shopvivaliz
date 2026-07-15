<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');
require_once dirname(__DIR__,2).'/includes/product-price-enrich.php';
require_once dirname(__DIR__,2).'/includes/catalog-runtime.php';
$products=svp_enrich_products(svcr_products());$available=[];foreach($products as $row){$stock=(int)($row['stock']??0);if($stock<=0)continue;$available[]=['sku'=>(string)($row['sku']??''),'olist_product_id'=>(string)($row['olist_product_id']??$row['id']??''),'slug'=>(string)($row['slug']??''),'name'=>(string)($row['name']??''),'category'=>(string)($row['category']??''),'image_url'=>(string)($row['image_url']??''),'price'=>(float)($row['price']??0),'stock'=>$stock];}
echo json_encode(['ok'=>true,'count'=>count($available),'products'=>$available],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
