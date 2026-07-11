<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once dirname(__DIR__,2).'/includes/product-price-enrich.php';
$path=__DIR__.'/fallback-products.json';
$rows=is_file($path)?json_decode((string)file_get_contents($path),true):[];
$products=[];foreach(is_array($rows)?$rows:[] as $row){if(is_array($row))$products[]=$row;}
$products=svp_enrich_products($products);
$total=count($products);$available=0;$out=0;$negative=0;$withPriceNoStock=0;
foreach($products as $row){$stock=(int)($row['stock']??0);$price=(float)($row['price']??0);if($stock>0)$available++;else{$out++;if($price>0)$withPriceNoStock++;}if($stock<0)$negative++;}
echo json_encode(['ok'=>true,'stock_health'=>['products_total'=>$total,'products_available'=>$available,'products_out_of_stock'=>$out,'products_negative_stock'=>$negative,'products_with_price_without_stock'=>$withPriceNoStock,'availability_percent'=>$total>0?round($available/$total*100,2):0]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);