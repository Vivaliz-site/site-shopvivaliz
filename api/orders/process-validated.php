<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/includes/order-request-context.php';
require_once dirname(__DIR__,2).'/includes/order-idempotency.php';

function svop_json(int $status,array $payload):never{http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function svop_root():string{return dirname(__DIR__,2);}
function svop_order_dir():string{$preferred=svop_root().'/storage/orders';if((is_dir($preferred)||@mkdir($preferred,0755,true))&&is_writable($preferred))return $preferred;$fallback=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'shopvivaliz-orders';if((is_dir($fallback)||@mkdir($fallback,0755,true))&&is_writable($fallback))return $fallback;return '';}
function svop_payment_method(string $value):string{$normalized=strtolower(trim($value));$allowed=['pix','boleto','whatsapp','transferencia'];return in_array($normalized,$allowed,true)?$normalized:'pix';}
function svop_payment_label(string $method):string{return match($method){'boleto'=>'Boleto bancario','whatsapp'=>'WhatsApp','transferencia'=>'Transferencia bancaria',default=>'PIX'};}
function svop_payment_instructions(string $method):string{return match($method){'boleto'=>'Boleto sujeito a emissao manual apos confirmacao do frete.','whatsapp'=>'Pagamento e frete serao alinhados pelo atendimento no WhatsApp.','transferencia'=>'Dados bancarios serao enviados pela equipe apos confirmacao do frete.',default=>'Pagamento via PIX com confirmacao apos validacao do pedido.'};}
function svop_append_log(array $order):void{$dir=svop_root().'/logs';if(!is_dir($dir))@mkdir($dir,0755,true);$entry=['id'=>$order['order_number']??'','timestamp'=>$order['created_at']??date('c'),'cliente'=>['nome'=>$order['customer']['name']??'','email'=>$order['customer']['email']??'','telefone'=>$order['customer']['phone']??'','endereco'=>$order['customer']['address']??'','cep'=>$order['customer']['cep']??''],'items'=>$order['items']??[],'payment_method'=>$order['payment_method']??'pix','status'=>'pendente_atendimento','source'=>'checkout_site_api','shipping_total'=>round((float)($order['shipping_total']??0),2),'shipping_label'=>(string)($order['shipping_label']??''),'total'=>round((float)($order['total']??0),2)];@file_put_contents($dir.'/pedidos.jsonl',json_encode($entry,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL,FILE_APPEND|LOCK_EX);}

$body=svorc_body();
$items=svorc_items();
$idempotencyKey=svoi_key($body,$items);
if($body===[]||$items===[])svop_json(500,['ok'=>false,'error'=>'validated_context_missing']);

$name=trim((string)($body['customer_name']??''));$email=trim((string)($body['customer_email']??''));$phone=trim((string)($body['customer_phone']??''));$cep=preg_replace('/\D+/','',(string)($body['cep']??''));$address=trim((string)($body['address']??''));$notes=trim((string)($body['notes']??''));
if(strlen($name)>120||strlen($email)>160||strlen($phone)>40||strlen($address)>300||strlen($notes)>1000){svoi_release($idempotencyKey);svop_json(422,['ok'=>false,'error'=>'field_too_long']);}
if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||$phone===''||strlen($cep)!==8||$address===''){svoi_release($idempotencyKey);svop_json(422,['ok'=>false,'error'=>'missing_required_fields']);}

$shippingTotal=round(max(0.0,(float)($body['shipping_total']??0)),2);$shippingLabel=trim((string)($body['shipping_label']??''));$shippingService=trim((string)($body['shipping_service']??''));$shippingCep=preg_replace('/\D+/','',(string)($body['shipping_cep']??$cep));$paymentMethod=svop_payment_method((string)($body['payment_method']??'pix'));
$itemsTotal=0.0;$cleanItems=[];foreach($items as $item){$price=round((float)($item['price']??0),2);$quantity=(int)($item['quantity']??0);$itemsTotal+=$price*$quantity;$cleanItems[]=['sku'=>(string)($item['sku']??''),'name'=>(string)($item['name']??''),'quantity'=>$quantity,'price'=>$price,'olist_product_id'=>(string)($item['olist_product_id']??'')];}
$orderNumber='SV'.date('YmdHis').random_int(100,999);$record=['order_number'=>$orderNumber,'status'=>'pending_confirmation','customer'=>['name'=>$name,'email'=>$email,'phone'=>$phone,'cep'=>$cep,'address'=>$address],'items'=>$cleanItems,'items_total'=>round($itemsTotal,2),'shipping_total'=>$shippingTotal,'shipping_label'=>$shippingLabel,'shipping_service'=>$shippingService,'shipping_cep'=>$shippingCep,'total'=>round($itemsTotal+$shippingTotal,2),'payment_method'=>$paymentMethod,'payment_label'=>svop_payment_label($paymentMethod),'notes'=>$notes,'created_at'=>date('c'),'source'=>'site_checkout_validated','idempotency_key_hash'=>hash('sha256',$idempotencyKey)];
$dir=svop_order_dir();if($dir===''){svoi_release($idempotencyKey);svop_json(500,['ok'=>false,'error'=>'order_storage_unavailable']);}
$path=$dir.'/'.$orderNumber.'.json';if(file_put_contents($path,json_encode($record,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX)===false){svoi_release($idempotencyKey);svop_json(500,['ok'=>false,'error'=>'order_write_failed']);}
svop_append_log($record);
svop_json(200,['ok'=>true,'order_number'=>$orderNumber,'status'=>'pending_confirmation','payment_method'=>$paymentMethod,'payment_label'=>$record['payment_label'],'message'=>'Pedido registrado para confirmacao manual de frete e pagamento.','payment_instructions'=>svop_payment_instructions($paymentMethod),'storage'=>str_contains($dir,'shopvivaliz-orders')?'fallback_temp':'storage_orders','subtotal'=>round($itemsTotal,2),'shipping_total'=>$shippingTotal,'shipping_label'=>$shippingLabel,'total'=>$record['total']]);
