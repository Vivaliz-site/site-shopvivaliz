<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/marketplace/AmazonClient.php';

final class SvAmazonRecoveryFinances
{
    public function __construct(private SvAmazonApi $api) {}

    /** @return list<array<string,mixed>> */
    public function listByOrder(string $orderId): array
    {
        $query=['relatedIdentifierName'=>'ORDER_ID','relatedIdentifierValue'=>$orderId];
        $out=[];
        do {
            $response=$this->api->request('GET','/finances/2024-06-19/transactions',$query);
            $data=$response['data'];
            foreach ((array)($data['transactions'] ?? []) as $tx) if (is_array($tx)) $out[]=$this->normalizeTransaction($tx,(string)$response['request_id']);
            $next=trim((string)($data['nextToken'] ?? ''));
            if ($next!=='') $query['nextToken']=$next; else unset($query['nextToken']);
        } while ($next!=='');
        return $out;
    }

    /** @return array<string,mixed> */
    private function normalizeTransaction(array $tx,string $requestId): array
    {
        $amount=0.0; $currency='BRL';
        foreach (['totalAmount','amount'] as $field) {
            if (!is_array($tx[$field] ?? null)) continue;
            $money=$tx[$field]; $amount=(float)($money['currencyAmount'] ?? $money['amount'] ?? 0); $currency=(string)($money['currencyCode'] ?? $money['currency'] ?? $currency); break;
        }
        $related=[];
        foreach ((array)($tx['relatedIdentifiers'] ?? []) as $r) if (is_array($r)) $related[(string)($r['relatedIdentifierName'] ?? '')]=(string)($r['relatedIdentifierValue'] ?? '');
        return ['transaction_id'=>(string)($tx['transactionId'] ?? $tx['id'] ?? ''),'transaction_type'=>strtoupper((string)($tx['transactionType'] ?? $tx['type'] ?? 'UNKNOWN')),'transaction_status'=>strtoupper((string)($tx['transactionStatus'] ?? '')),'amount'=>round(abs($amount),2),'currency'=>$currency,'amazon_order_id'=>(string)($related['ORDER_ID'] ?? ''),'posted_at'=>(string)($tx['postedDate'] ?? $tx['postedAt'] ?? ''),'request_id'=>$requestId,'raw'=>$tx];
    }
}
