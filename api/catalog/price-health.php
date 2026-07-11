<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once dirname(__DIR__,2).'/includes/product-price-enrich.php';
$path=__DIR__.'/fallback-products.json';
$rows=is_file($path)?json_decode((string)file_get_contents($path),true):[];
$products=[];
foreach(is_array($rows)?$rows:[] as $row){if(is_array($row))$products[]=$row;}
$enriched=svp_enrich_products($products);
$total=count($enriched);$withPrice=0;$withoutPrice=0;$withStockNoPrice=0;$outOfStock=0;
foreach($enriched as $row){$price=(float)($row['price']??0);$stock=(int)($row['stock']??0);if($price>0)$withPrice++;else{$withoutPrice++;if($stock>0)$withStockNoPrice++;}if($stock<=0)$outOfStock++;}
echo json_encode(['ok'=>true,'price_health'=>['products_total'=>$total,'products_with_price'=>$withPrice,'products_without_price'=>$withoutPrice,'products_with_stock_without_price'=>$withStockNoPrice,'products_out_of_stock'=>$outOfStock,'coverage_percent'=>$total>0?round($withPrice/$total*100,2):0]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);