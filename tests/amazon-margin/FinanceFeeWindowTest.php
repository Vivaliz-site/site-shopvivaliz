<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/marketplace/AmazonPublisher.php';
require_once __DIR__.'/../../includes/amazon-margin/AuditCollector.php';
putenv('AMAZON_SELLER_ID=TEST-SELLER');

final class SvMarginFakeAmazon extends SvAmazonClient {
 public function __construct() {}
 public function marketplaceId():string{return 'A2Q3Y263D00KWC';}
 public function request(string $method,string $path,array $query=[],?array $body=null):array {
    if($path!=='/finances/2024-06-19/transactions')throw new RuntimeException('unexpected SP-API method');
    $id='100-1111111-2222222';
    $identifiers=[['relatedIdentifierName'=>'ORDER_ID','relatedIdentifierValue'=>$id]];
    return [
      'status'=>200,'request_id'=>'test-id',
      'data'=>['payload'=>['transactions'=>[
        ['transactionType'=>'ServiceFee','description'=>'MfnPostageFee','relatedIdentifiers'=>$identifiers,
         'postedDate'=>'2026-09-20T14:00:00Z','totalAmount'=>['currencyAmount'=>-9.90]],
        ['transactionType'=>'ServiceFee','description'=>'MfnPostageFee','relatedIdentifiers'=>$identifiers,
         'postedDate'=>'2026-10-18T16:00:00Z','totalAmount'=>['currencyAmount'=>-6.70]],
        ['transactionType'=>'Shipment','relatedIdentifiers'=>$identifiers,'postedDate'=>'2026-09-20T14:00:00Z',
         'items'=>[['contexts'=>[['sku'=>'ABC-1','quantityShipped'=>1]],
                    'totalAmount'=>['currencyAmount'=>120.0]]]],
        ['transactionType'=>'Shipment','relatedIdentifiers'=>$identifiers,'postedDate'=>'2026-10-18T16:00:00Z',
         'items'=>[['contexts'=>[['sku'=>'ABC-1','quantityShipped'=>1]],
                    'totalAmount'=>['currencyAmount'=>130.0]]]]
      ],'nextToken'=>null]]
    ];
 }
}
$collector=new SvAmazonMarginCollector(new SvMarginFakeAmazon(),'fake-test-erp-token');
$method=new ReflectionMethod($collector,'finances');
$history=$method->invoke($collector,'2026-09-01T00:00:00Z','2026-10-01T00:00:00Z');
$order='100-1111111-2222222';
$fees=$history['fees'][$order]??[];
if(count($fees)!==1||abs($fees[0]+6.70)>0.0001)
  throw new RuntimeException('old 90d DBA postage wrongly included in 30d financial window');
if(count($history['shipments']['ABC-1']??[])!==2)
  throw new RuntimeException('old order shipments must remain available for ERP identity');
if(!isset($history['order_skus'][$order]['ABC-1']))
  throw new RuntimeException('order-to-SellerSKU identity lost');
echo "PASS DBA fee separation: 90-day product identity; fees only in 30-day report; no double counting\n";
