<?php
declare(strict_types=1);

/**
 * Read-only identity join via the OFFICIAL Olist ERP v3 Orders endpoints.
 *
 * Amazon Finances: ORDER_ID -> exact Seller SKU on a single-SKU order.
 * Olist: GET /pedidos?numeroPedidoEcommerce=ORDER_ID -> one imported order.
 * Olist: GET /pedidos/{id} -> one product ID on exactly one item row.
 *
 * A product's GTIN may be different from the Amazon Catalog GTIN. This is
 * precisely why we need the order join instead of trusting matching titles.
 * Never store order IDs, customers, addresses, or other order PII in the report.
 */
final class SvAmazonMarginOlistOrderLink
{
    public const INTEGRATIONS=['Amazon','Amazon Onsite','Amazon Classic'];

    /** Imported order history may be 90 days; only report-period sales can set margin. */
    public static function inFinancialWindow(string $postedAt,string $periodStart): bool
    {
        $at=strtotime($postedAt);
        $start=strtotime($periodStart);
        return $at!==false && $start!==false && $at >= $start;
    }

    /**
     * @param array<array{order:string,quantity:int,posted_at:string,net_amount:float}> $shipments
     * @param array<string,array<string,bool>> $orderSkus
     * @param callable(string):array $get GET to documented Olist/Tiny v3 endpoint
     * @return array{status:string,id:?int,method:?string,reason:string,integration:?string,evidence_count:int}
     */
    public static function resolve(string $sellerSku, array $shipments,
                                   array $orderSkus, callable $get): array
    {
        $none=['status'=>'unmapped_erp','id'=>null,'method'=>null,
            'reason'=>'no_verified_erp_amazon_order','integration'=>null,'evidence_count'=>0];
        $latest=[];
        foreach($shipments as $shipment) {
            $order=trim((string)($shipment['order']??''));
            if ($order==='' || !preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/',$order)) continue;
            $skuSet=$orderSkus[$order]??[];
            if (count($skuSet)!==1 || !isset($skuSet[$sellerSku])) continue;
            $posted=(string)($shipment['posted_at']??'');
            if (!isset($latest[$order]) || $posted>$latest[$order])$latest[$order]=$posted;
        }
        if (!$latest)return $none;
        arsort($latest,SORT_STRING);
        $seen=[];
        $channels=[];
        $qualified=0;
        // Three independent orders at most; bounded API load per Seller SKU.
        foreach(array_slice(array_keys($latest),0,3) as $amazonOrder) {
            $list=$get('/pedidos?numeroPedidoEcommerce='.rawurlencode($amazonOrder)
                .'&limit=10&offset=0');
            $matches=[];
            foreach($list['itens']??[] as $candidate){
                if(!is_array($candidate))continue;
                $ecommerce=$candidate['ecommerce']??[];
                if(($ecommerce['numeroPedidoEcommerce']??null)!==$amazonOrder)continue;
                if(!in_array((string)($ecommerce['nome']??''),self::INTEGRATIONS,true))continue;
                $id=(int)($candidate['id']??0);
                if($id<1 || (int)($ecommerce['id']??0)<1)continue;
                $matches[$id]=$candidate;
            }
            if(count($matches)!==1)continue;
            $header=reset($matches);
            $detail=$get('/pedidos/'.(int)$header['id']);
            $integration=$detail['ecommerce']??[];
            if (($detail['id']??null)!==$header['id']
                || ($integration['numeroPedidoEcommerce']??null)!==$amazonOrder
                || (int)($integration['id']??0)!==(int)$header['ecommerce']['id']
                || ($integration['nome']??null)!==$header['ecommerce']['nome']) continue;
            $items=$detail['itens']??[];
            if(!is_array($items) || count($items)!==1 || !is_array($items[0]??null))continue;
            $erpId=(int)($items[0]['produto']['id']??0);
            if($erpId<1)continue;
            $seen[$erpId]=($seen[$erpId]??0)+1;
            $channels[$integration['nome']]=true;
            $qualified++;
        }
        if(count($seen)>1)return [
            'status'=>'ambiguous_erp','id'=>null,'method'=>null,
            'reason'=>'historical_amazon_orders_disagree_on_product',
            'integration'=>null,'evidence_count'=>$qualified
        ];
        if(count($seen)!==1)return $none;
        return [
            'status'=>'mapped','id'=>(int)array_key_first($seen),
            'method'=>'OLIST_AMAZON_ORDER',
            'reason'=>'amazon_finance_order_id_verified_against_olist_single_product_order',
            'integration'=>implode(', ',array_keys($channels)),
            'evidence_count'=>$qualified
        ];
    }
}
