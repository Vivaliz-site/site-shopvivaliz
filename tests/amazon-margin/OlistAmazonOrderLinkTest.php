<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/OlistAmazonOrderLink.php';
function check(bool $ok,string $msg):void {
 if(!$ok)throw new RuntimeException('OlistAmazonOrderLink: '.$msg);
}
$mk=function(string $name,string $order,int $orderId,int $erpId):array{
 return [
  'listing'=>['id'=>$orderId,'ecommerce'=>[
    'id'=>105,'nome'=>$name,'numeroPedidoEcommerce'=>$order]],
  'detail'=>['id'=>$orderId,'ecommerce'=>[
    'id'=>105,'nome'=>$name,'numeroPedidoEcommerce'=>$order],
    'itens'=>[['produto'=>['id'=>$erpId,'sku'=>'ERP-REAL']]]
  ]
 ];
};
$orders=['100-1111111-2222222','100-1111111-2222223','100-1111111-2222224'];
$fixtures=[
 $orders[0]=>$mk('Amazon Onsite',$orders[0],301,7001),
 $orders[1]=>$mk('Amazon Classic',$orders[1],302,7001),
 $orders[2]=>$mk('Amazon',$orders[2],303,7001),
];
$fetch=function(string $url)use(&$fixtures):array{
 if(str_starts_with($url,'/pedidos?')) {
   parse_str(parse_url($url,PHP_URL_QUERY)??'',$q);
   $order=(string)($q['numeroPedidoEcommerce']??'');
   return isset($fixtures[$order])
     ? ['itens'=>[$fixtures[$order]['listing']],'paginacao'=>['total'=>1]]
     : ['itens'=>[]];
 }
 if(preg_match('#^/pedidos/(\d+)$#',$url,$m)){
   foreach($fixtures as $f)if($f['listing']['id']===(int)$m[1])return $f['detail'];
   return [];
 }
 throw new RuntimeException('unexpected endpoint '.$url);
};
$sku='SELLER-ABC';
$ships=[];$map=[];
foreach($orders as $order){
 $ships[]=['order'=>$order,'quantity'=>1,'posted_at'=>'2026-10-18T00:00:00Z','net_amount'=>100.0];
 $map[$order]=[$sku=>true];
}
$r=SvAmazonMarginOlistOrderLink::resolve($sku,$ships,$map,$fetch);
check($r['status']==='mapped'&&$r['id']===7001&&$r['evidence_count']===3,'all three integrations map to same product');
check(str_contains($r['integration'],'Amazon Onsite')&&str_contains($r['integration'],'Amazon Classic')
 && str_contains($r['integration'],'Amazon'),'integration provenance');
$wrong=$fixtures;
$fixtures[$orders[2]]=$mk('Amazon',$orders[2],303,7002);
$r=SvAmazonMarginOlistOrderLink::resolve($sku,$ships,$map,$fetch);
check($r['status']==='ambiguous_erp'&&$r['id']===null,'conflicting order histories must not choose cost');
$fixtures=$wrong;
$multiMap=$map;
$multiMap[$orders[0]]=['SELLER-ABC'=>true,'ANOTHER-SKU'=>true];
$r=SvAmazonMarginOlistOrderLink::resolve($sku,[$ships[0]],$multiMap,$fetch);
check($r['status']==='unmapped_erp','multi-SKU Amazon order cannot be used');
$fake=$fixtures;
$fake[$orders[0]]['detail']['ecommerce']['numeroPedidoEcommerce']='100-0000000-0000000';
$fixtures=$fake;
$r=SvAmazonMarginOlistOrderLink::resolve($sku,[$ships[0]],$map,$fetch);
check($r['status']==='unmapped_erp','order detail must confirm Amazon order ID');
$fixtures=$wrong;
$fake=$fixtures;
$fake[$orders[0]]['listing']['ecommerce']['nome']='Mercado Livre';
$fixtures=$fake;
$r=SvAmazonMarginOlistOrderLink::resolve($sku,[$ships[0]],$map,$fetch);
check($r['status']==='unmapped_erp','cannot mix other marketplace integration');
$fixtures=$wrong;
$r=SvAmazonMarginOlistOrderLink::resolve($sku,[[
 'order'=>'<bad>','quantity'=>1,'posted_at'=>'2026-10-18T00:00:00Z'
]],[], $fetch);
check($r['status']==='unmapped_erp','invalid external order identifier ignored');

check(SvAmazonMarginOlistOrderLink::inFinancialWindow('2026-10-15T00:00:00Z','2026-10-15T00:00:00Z'),'inclusive financial cutoff');
check(SvAmazonMarginOlistOrderLink::inFinancialWindow('2026-10-16T01:00:00.349Z','2026-10-15T00:00:00Z'),'fractional ISO time');
check(!SvAmazonMarginOlistOrderLink::inFinancialWindow('2026-09-14T23:59:59Z','2026-10-15T00:00:00Z'),'historical order cannot inflate monthly margin');
check(!SvAmazonMarginOlistOrderLink::inFinancialWindow('','2026-10-15T00:00:00Z'),'absent postedAt cannot be treated as sale');
echo "PASS Olist Amazon three integrations order-join: exact id, product, conflicts, non-Amazon/multi-SKU negatives\n";
