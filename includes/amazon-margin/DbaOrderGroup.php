<?php
declare(strict_types=1);

require_once __DIR__.'/MarginMath.php';

/**
 * Aggregate same-SKU shipments from ONE DBA order before attributing the
 * single order-level MfnPostageFee. The calling collector has already
 * restricted shipments to its financial time window and still verifies that
 * the Amazon order contains precisely one Seller SKU and is DBA eligible.
 *
 * No order/customer identifiers are persisted in the audit snapshot.
 */
final class SvAmazonMarginDbaOrderGroup
{
    /**
     * @param list<array<string,mixed>> $shipments Financial-window shipments only.
     * @return list<array<string,mixed>>
     */
    public static function combine(array $shipments): array
    {
        $groups = [];
        foreach ($shipments as $i=>$shipment) {
            if (!is_array($shipment)) throw new InvalidArgumentException('invalid_dba_shipment');
            $order=trim((string)($shipment['order']??''));
            // Never accidentally group unrelated rows with missing ORDER_ID.
            $key=$order!=='' ? 'order:'.$order : 'unknown:'.$i;
            if (!isset($groups[$key])) {
                $groups[$key]=[
                    'order'=>$order,
                    'quantity'=>0,
                    'net_cents'=>0,
                    'sale_gross_cents'=>0,
                    'source_rows'=>0,
                    'posted_at'=>(string)($shipment['posted_at']??''),
                    'verified'=>true,
                ];
            }
            $groups[$key]['source_rows']++;
            $quantity=(int)($shipment['quantity']??0);
            $net=SvAmazonMarginMath::cents($shipment['net_amount']??null);
            $gross=$shipment['sale_gross_cents']??null;
            if ($quantity<1 || $net===null || !is_int($gross) || $gross<=0) {
                $groups[$key]['verified']=false;
                continue;
            }
            $groups[$key]['quantity']+=$quantity;
            $groups[$key]['net_cents']+=$net;
            $groups[$key]['sale_gross_cents']+=$gross;
        }
        foreach ($groups as &$group) {
            $group['net_amount']=round($group['net_cents']/100,2);
            if (!$group['verified']) $group['sale_gross_cents']=null;
            unset($group['net_cents'],$group['verified']);
        }
        unset($group);
        return array_values($groups);
    }
}
