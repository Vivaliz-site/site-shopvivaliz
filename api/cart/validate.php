<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once dirname(__DIR__,2).'/includes/catalog-runtime.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);exit;}
$input=json_decode((string)file_get_contents('php://input'),true);$items=is_array($input['items']??null)?$input['items']:[];if($items===[]){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'empty_cart']);exit;}
$catalog=svcr_products();$bySku=[];foreach($catalog as $row){if(is_array($row)&&trim((string)($row['sku']??''))!=='')$bySku[trim((string)$row['sku'])]=$row;}
$valid=[];$errors=[];$total=0.0;foreach($items as $item){$sku=trim((string)($item['sku']??''));$qty=max(1,min(99,(int)($item['quantity']??1)));if($sku===''||!isset($bySku[$sku])){$errors[]=['sku'=>$sku,'error'=>'product_not_found'];continue;}$row=$bySku[$sku];$price=(float)($row['price']??0);$stock=(int)($row['stock']??0);if($price<=0){$errors[]=['sku'=>$sku,'error'=>'invalid_price'];continue;}if($stock<$qty){$errors[]=['sku'=>$sku,'error'=>'insufficient_stock','available'=>$stock];continue;}$subtotal=$price*$qty;$total+=$subtotal;$valid[]=['sku'=>$sku,'name'=>(string)($row['name']??''),'quantity'=>$qty,'unit_price'=>round($price,2),'subtotal'=>round($subtotal,2),'stock'=>$stock];}
$status=$errors===[]?200:422;http_response_code($status);echo json_encode(['ok'=>$errors===[],'items'=>$valid,'errors'=>$errors,'total'=>round($total,2)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
