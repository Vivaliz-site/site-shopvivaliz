<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/marketplace/AmazonClient.php';

final class SvAmazonRecoveryOrders
{
    public function __construct(private SvAmazonApi $api) {}

    /** @return array<string,mixed> */
    public function getOrder(string $orderId): array
    {
        $response=$this->api->request('GET','/orders/2026-01-01/orders/'.rawurlencode($orderId),['includedData'=>'FULFILLMENT,PROCEEDS,EXPENSE,PACKAGES,PAYMENT']);
        $data=$response['data'];
        $order=is_array($data['order'] ?? null)?$data['order']:(is_array($data['payload'] ?? null)?$data['payload']:$data);
        return $this->normalizeOrder($order,(string)$response['request_id']);
    }

    /** @return list<array<string,mixed>> */
    public function searchUpdated(DateTimeImmutable $after,?string $paginationToken=null): array
    {
        $query=['lastUpdatedAfter'=>$after->format(DATE_ATOM),'marketplaceIds'=>$this->api->marketplaceId(),'includedData'=>'FULFILLMENT,PROCEEDS,EXPENSE,PACKAGES,PAYMENT'];
        if ($paginationToken!==null && $paginationToken!=='') $query['paginationToken']=$paginationToken;
        $response=$this->api->request('GET','/orders/2026-01-01/orders',$query);
        $data=$response['data'];
        $orders=is_array($data['orders'] ?? null)?$data['orders']:(is_array($data['payload']['orders'] ?? null)?$data['payload']['orders']:[]);
        $normalized=[];
        foreach ($orders as $order) if (is_array($order)) $normalized[]=$this->normalizeOrder($order,(string)$response['request_id']);
        return $normalized;
    }

    /** @return array<string,mixed> */
    private function normalizeOrder(array $order,string $requestId): array
    {
        $programs=array_values(array_filter(array_map('strval',is_array($order['programs'] ?? null)?$order['programs']:[])));
        $program='';
        foreach (['DELIVERY_BY_AMAZON','FBA_ONSITE','AMAZON_EASY_SHIP','PRIME'] as $candidate) if (in_array($candidate,$programs,true)) { $program=$candidate; break; }
        if ($program==='' && $programs!==[]) $program=$programs[0];
        $items=[];
        foreach ((array)($order['orderItems'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $product=is_array($item['product'] ?? null)?$item['product']:[];
            $items[]=['amazon_order_item_id'=>(string)($item['orderItemId'] ?? ''),'asin'=>(string)($product['asin'] ?? ''),'seller_sku'=>(string)($product['sellerSku'] ?? ''),'quantity_ordered'=>(int)($item['quantityOrdered'] ?? 0),'raw'=>$item];
        }
        $marketplaceId=(string)($order['salesChannel']['marketplaceId'] ?? $order['marketplaceId'] ?? $this->api->marketplaceId());
        $fulfillment=is_array($order['fulfillment'] ?? null)?$order['fulfillment']:[];
        return ['amazon_order_id'=>(string)($order['orderId'] ?? $order['amazonOrderId'] ?? ''),'marketplace_id'=>$marketplaceId,'order_date'=>(string)($order['createdTime'] ?? $order['purchaseDate'] ?? ''),'last_updated_at'=>(string)($order['lastUpdatedTime'] ?? ''),'amazon_program'=>$program,'programs'=>$programs,'fulfillment_channel'=>(string)($fulfillment['fulfilledBy'] ?? $fulfillment['channel'] ?? ''),'items'=>$items,'packages'=>is_array($order['packages'] ?? null)?$order['packages']:[],'request_id'=>$requestId,'raw'=>$order];
    }
}
