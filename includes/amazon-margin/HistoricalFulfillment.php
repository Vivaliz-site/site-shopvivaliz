<?php
declare(strict_types=1);

/**
 * Historical Amazon Orders fulfillment evidence is independent of a currently
 * buyable Pricing offer. Only the official v2026-01-01 Order fulfillment field
 * can restore a historical channel when the current offer is unavailable.
 * This class never infers a program from Seller SKU, title or a past snapshot.
 */
final class SvAmazonMarginHistoricalFulfillment
{
    /** @return array{channel:string,is_dba:bool} */
    public static function fromOrder(array $response): array
    {
        $order = $response['order'] ?? $response['payload'] ?? $response;
        if (!is_array($order)) {
            return ['channel'=>'UNKNOWN','is_dba'=>false];
        }
        $channel = $order['fulfillment']['fulfilledBy'] ?? null;
        if (!in_array($channel,['AMAZON','MERCHANT'],true)) $channel='UNKNOWN';
        $programs = $order['programs'] ?? [];
        $isDba = is_array($programs) && in_array('DELIVERY_BY_AMAZON',$programs,true);
        return ['channel'=>$channel,'is_dba'=>$isDba];
    }

    /**
     * Every in-period order must independently and consistently attest its
     * channel; merchant orders also require the official DBA program.
     * Missing, mixed or contradictory order evidence never gets certified.
     *
     * @param list<array{channel:string,is_dba:bool}> $orders
     */
    public static function resolve(array $orders): ?string
    {
        if ($orders === []) return null;
        $channel=null;
        foreach ($orders as $order) {
            $current = $order['channel'] ?? 'UNKNOWN';
            if (!in_array($current,['AMAZON','MERCHANT'],true)) return null;
            if ($current === 'MERCHANT' && !($order['is_dba'] ?? false)) return null;
            if ($channel !== null && $current !== $channel) return null;
            $channel=$current;
        }
        return $channel;
    }
}
