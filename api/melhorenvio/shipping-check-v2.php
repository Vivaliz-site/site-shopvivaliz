<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');

require_once dirname(__DIR__, 2) . '/includes/product-price-enrich.php';
require_once dirname(__DIR__, 2) . '/includes/melhorenvio-oauth.php';
require_once dirname(__DIR__, 2) . '/includes/catalog-runtime.php';

function svsh_json(int $status, array $payload): never { http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function svsh_env(string ...$keys): string { svp_env_load(); foreach ($keys as $key) { $value = getenv($key); if (is_string($value) && trim($value) !== '') return trim($value); if (isset($_ENV[$key]) && is_string($_ENV[$key]) && trim($_ENV[$key]) !== '') return trim($_ENV[$key]); } return ''; }
function svsh_secret(): string { $secret=svsh_env('QUOTE_SIGNING_KEY','APP_KEY','SHOPVIVALIZ_APP_KEY','SHOPVIVALIZ_AGENT_KEY'); if($secret==='')svsh_json(503,['ok'=>false,'error'=>'quote_signing_key_missing','message'=>'Assinatura segura de frete não configurada.']); return $secret; }
function svsh_catalog(): array { static $rows = null; if ($rows !== null) return $rows; return $rows = svcr_products(); }
function svsh_product(string $sku, string $id): array { foreach (svsh_catalog() as $row) { if (!is_array($row)) continue; $rowSku = trim((string)($row['sku'] ?? '')); $rowId = trim((string)($row['id'] ?? $row['olist_product_id'] ?? '')); if (($sku !== '' && strcasecmp($rowSku, $sku) === 0) || ($id !== '' && $rowId === $id)) return $row; } return []; }
function svsh_number(array $row, array $keys, float $fallback): float { foreach ($keys as $key) { $value = (float)($row[$key] ?? 0); if ($value > 0) return $value; } return $fallback; }
function svsh_post(string $url, array $payload, string $token): array { if (!function_exists('curl_init')) return ['ok'=>false,'status'=>0,'body'=>[]]; $ch = curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json','Authorization: Bearer '.$token,'User-Agent: ShopVivaliz/Shipping-v2']]); $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); $body=is_string($raw)?json_decode($raw,true):[]; return ['ok'=>$status>=200&&$status<300,'status'=>$status,'body'=>is_array($body)?$body:[]]; }
function svsh_quote_id(string $cep, array $items, array $option, int $expiresAt): string { $fingerprint=['cep'=>$cep,'items'=>$items,'service_id'=>(string)($option['id']??''),'price'=>round((float)($option['price']??0),2),'expires_at'=>$expiresAt]; return hash_hmac('sha256',json_encode($fingerprint,JSON_UNESCAPED_SLASHES),svsh_secret()); }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') svsh_json(405,['ok'=>false,'error'=>'method_not_allowed']);
$raw=file_get_contents('php://input')?:''; if(strlen($raw)>100000) svsh_json(413,['ok'=>false,'error'=>'payload_too_large']);
$body=json_decode($raw,true); if(!is_array($body)) svsh_json(400,['ok'=>false,'error'=>'invalid_json']);
$cep=preg_replace('/\D+/','',(string)($body['cep']??'')); if(strlen($cep)!==8) svsh_json(422,['ok'=>false,'error'=>'invalid_cep']);
$items=is_array($body['items']??null)?array_slice($body['items'],0,50):[]; if($items===[]) svsh_json(422,['ok'=>false,'error'=>'empty_items']);
$products=[];$fingerprintItems=[];
foreach($items as $item){ if(!is_array($item))continue; $sku=trim((string)($item['sku']??'')); $id=trim((string)($item['product_id']??$item['olist_product_id']??'')); $row=svsh_product($sku,$id); if($row===[])svsh_json(404,['ok'=>false,'error'=>'product_not_found','sku'=>$sku]); $quantity=max(1,min(99,(int)($item['quantity']??1))); $price=max(1.0,(float)($row['price']??0)); $products[]=['id'=>(string)($row['id']??$row['olist_product_id']??$row['sku']??'produto'),'width'=>max(1,(int)round(svsh_number($row,['width','largura'],16))),'height'=>max(1,(int)round(svsh_number($row,['height','altura'],16))),'length'=>max(1,(int)round(svsh_number($row,['length','comprimento'],16))),'weight'=>max(.1,svsh_number($row,['weight','peso'],1)),'insurance_value'=>$price,'quantity'=>$quantity]; $fingerprintItems[]=['sku'=>(string)($row['sku']??$sku),'quantity'=>$quantity,'price'=>round($price,2)]; }
$token=me_current_access_token()?:svsh_env('MELHORENVIO_ACCESS_TOKEN','SHOPVIVALIZ_MELHORENVIO_ACCESS_TOKEN','MELHORENVIO_API_KEY'); if($token==='')svsh_json(503,['ok'=>false,'error'=>'missing_access_token','message'=>'Frete temporariamente indisponível.']);
$from=preg_replace('/\D+/','',svsh_env('MELHORENVIO_FROM_POSTAL_CODE','SHOPVIVALIZ_FROM_POSTAL_CODE'))?:'35501236';
$result=svsh_post(me_api_base().'/api/v2/me/shipment/calculate',['from'=>['postal_code'=>$from],'to'=>['postal_code'=>$cep],'products'=>$products,'options'=>['receipt'=>false,'own_hand'=>false,'collect'=>false]],$token); if(!$result['ok'])svsh_json(502,['ok'=>false,'error'=>'provider_error','message'=>'Não foi possível consultar o frete agora.']);
$options=[]; foreach($result['body'] as $option){ if(!is_array($option)||!empty($option['error']))continue; $price=(float)($option['price']??0); if($price<=0)continue; $options[]=['id'=>(string)($option['id']??''),'name'=>(string)($option['name']??$option['company']['name']??'Frete'),'company'=>(string)($option['company']['name']??''),'price'=>round($price,2),'delivery_time'=>max(0,(int)($option['delivery_time']??0))]; }
usort($options,static fn(array $a,array $b):int=>$a['price']<=>$b['price']); $options=array_slice($options,0,6); if($options===[])svsh_json(404,['ok'=>false,'error'=>'no_shipping_options']);
$expiresAt=time()+1800; foreach($options as &$option){$option['quote_id']=svsh_quote_id($cep,$fingerprintItems,$option,$expiresAt);$option['expires_at']=$expiresAt;} unset($option);
$selected=$options[0];
svsh_json(200,['ok'=>true,'provider'=>'melhorenvio','cep'=>$cep,'shipping_options'=>$options,'shipping_total'=>$selected['price'],'selected_option'=>$selected,'quote_id'=>$selected['quote_id'],'expires_at'=>$expiresAt]);
