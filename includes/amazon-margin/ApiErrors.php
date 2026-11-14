<?php
declare(strict_types=1);

/**
 * Maps exceptions raised by the margin admin API to an HTTP status and a stable,
 * machine-readable code. Business refusals (stale preview, shared ERP product,
 * unpublishable proposal) must NOT be reported as "storage unavailable": the
 * operator needs the real reason, and the server log needs the message.
 */
final class SvAmazonMarginApiErrors
{
    /** Known business refusals raised as RuntimeException: HTTP 409. */
    private const CONFLICTS=[
        'price_preview_changed_recalculate',
        'price_proposal_not_ready_for_publication',
        'price_request_erp_shared_id_conflict',
        'price_skus_empty_after_cart_exclusion',
        'owner_tool_cart_price_exception',
    ];
    /** Known input refusals (InvalidArgumentException / JsonException): HTTP 400. */
    private const INPUT=[
        'price_request_invalid_identity_or_nonce','price_skus_empty',
        'margin_target_out_of_range','skus_must_be_array','too_many_skus',
        'invalid_sku','invalid_scope','selected_sku_count_invalid',
        'invalid_seller_sku','price_request_seller_sku_invalid_or_duplicate',
    ];

    /** @return array{status:int,body:array<string,mixed>,log:?string} */
    public static function map(Throwable $e): array
    {
        $message=$e->getMessage();
        if ($e instanceof JsonException) {
            return ['status'=>400,'body'=>['ok'=>false,'error'=>'invalid_input','code'=>'invalid_json'],'log'=>null];
        }
        if ($e instanceof InvalidArgumentException) {
            $body=['ok'=>false,'error'=>'invalid_input'];
            if (in_array($message,self::INPUT,true)) $body['code']=$message;
            return ['status'=>400,'body'=>$body,'log'=>null];
        }
        if (in_array($message,self::CONFLICTS,true)) {
            return ['status'=>409,'body'=>['ok'=>false,'error'=>$message,'code'=>$message],'log'=>null];
        }
        if (in_array($message,self::INPUT,true)) {
            return ['status'=>400,'body'=>['ok'=>false,'error'=>'invalid_input','code'=>$message],'log'=>null];
        }
        $safe=preg_replace('/[^a-zA-Z0-9_:-]/','_',substr($message,0,90));
        return [
            'status'=>503,
            'body'=>['ok'=>false,'error'=>'audit_storage_unavailable',
                'detail'=>'Awaiting first audited snapshot or schema deployment.'],
            'log'=>'amazon_margin_admin_api_failure: '.get_class($e).':'.$safe,
        ];
    }
}
