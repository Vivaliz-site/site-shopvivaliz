<?php
declare(strict_types=1);

require_once __DIR__.'/TargetMarginPriceJobs.php';
require_once __DIR__.'/TargetMarginPublication.php';
require_once __DIR__.'/TargetMarginPricePlan.php';
require_once __DIR__.'/CurrentFeeEstimates.php';
require_once __DIR__.'/AuditStore.php';
require_once __DIR__.'/AuditCollector.php';
require_once dirname(__DIR__).'/marketplace/AmazonPublisher.php';
require_once dirname(__DIR__).'/marketplace/TinyV3Runtime.php';
require_once dirname(__DIR__).'/tiny-order-push.php';

/**
 * P2 commercial worker. Operates only on explicit, durable admin requests.
 * A repair attempt NEVER resends an unknown PUT/PATCH outcome; it only reads
 * back and leaves an actionable manual-review record.
 */
final class SvAmazonTargetMarginPriceWorker
{
    private const SKU_STAGES_NO_RETRY=[
        'erp_submit_started','amazon_submit_started','awaiting_readback',
    ];

    /** One invocation processes one SKU and leaves a durable checkpoint. */
    public static function runNext(PDO $db): array
    {
        if ((int)$db->query("SELECT GET_LOCK('sv_amazon_target_price_worker',0)")->fetchColumn()!==1)
            return ['state'=>'busy'];
        try{
            $item=SvAmazonTargetMarginPriceJobs::next($db);
            if(!$item)return ['state'=>'empty'];
            $id=(int)$item['id'];$sku=(string)$item['seller_sku'];
            try{
                $client=new SvAmazonClient();
                $token=sv_market_tiny_ensure_access_token();
                if($token==='')throw new RuntimeException('tiny_access_token_missing');
                $status=self::runItem($db,$item,$client,$token);
                return ['state'=>$status,'item_id'=>$id,'seller_sku'=>$sku];
            }catch(SvMarketplaceException $e){
                // Treat any 401/403 as an explicit service refusal; never
                // attempt alternate identities or bypass this response.
                $code=in_array($e->httpStatus,[401,403],true)?'provider_authorization_denied'
                    :($e->httpStatus===429?'provider_quota_deferred':'provider_http_failure');
                $liveStage=$db->prepare('SELECT stage FROM amazon_margin_target_price_items WHERE id=?');
                $liveStage->execute([$id]);
                $stage=(string)$liveStage->fetchColumn();
                $state='manual_review';
                if(in_array($stage,['awaiting_readback','amazon_submit_started'],true))
                    $state='awaiting_readback'; // GET/readback only, never PATCH again
                elseif(in_array($stage,['queued','pricing_quote','erp_verified'],true)
                    &&($e->httpStatus===429||$e->httpStatus>=500))
                    $state='queued'; // safe retry for read-only step

                // A retry after a confirmed Olist PUT resumes at erp_verified:
                // a queued checkpoint must never repeat the commercial ERP PUT.
                $nextStage=$state==='queued'&&$stage==='erp_verified'
                    ?'erp_verified':$state;
                SvAmazonTargetMarginPriceJobs::transition($db,$id,$state,$nextStage,
                    $item['result_price_cents']??null,$code);
                return ['state'=>$state,'code'=>$code,'item_id'=>$id];
            }catch(Throwable $e){
                // Do not put provider error bodies or tokens in the journal.
                $liveStage=$db->prepare('SELECT stage FROM amazon_margin_target_price_items WHERE id=?');
                $liveStage->execute([$id]);
                $stage=(string)$liveStage->fetchColumn();
                $state=in_array($stage,['awaiting_readback','amazon_submit_started'],true)
                    ?'awaiting_readback':'manual_review';
                if(in_array($stage,['queued','pricing_quote','erp_verified'],true)
                    &&in_array(get_class($e),[RuntimeException::class],true)
                    &&in_array($e->getMessage(),[
                        'amazon_listing_get_failed','amazon_effective_offer_not_unique',
                        'amazon_effective_price_unverified','olist_991_get_failed',
                        'amazon_quote_at_candidate_unavailable'
                    ],true))$state='queued';
                $nextStage=$state==='queued'&&$stage==='erp_verified'
                    ?'erp_verified':$state;
                SvAmazonTargetMarginPriceJobs::transition($db,$id,$state,$nextStage,
                    $item['result_price_cents']??null,get_class($e));
                return ['state'=>$state,'code'=>get_class($e),'item_id'=>$id];
            }
        } finally {
            $db->query("SELECT RELEASE_LOCK('sv_amazon_target_price_worker')");
        }
    }

    private static function readOlist(string $token): array
    {
        $r=svtop_tiny_get('/listas-precos/991',$token);
        if((int)($r['status']??0)!==200||!is_array($r['json']??null))
            throw new RuntimeException('olist_991_get_failed');
        sveap_parse_table($r['json']);
        return $r['json'];
    }

    private static function readListing(SvAmazonClient $client,string $sku): array
    {
        $r=$client->request('GET','/listings/2021-08-01/items/'.
            rawurlencode($client->sellerId()).'/'.rawurlencode($sku),[
            'marketplaceIds'=>$client->marketplaceId(),
            'includedData'=>'summaries,attributes,issues',
        ]);
        if((int)$r['status']!==200||!is_array($r['data']))
            throw new RuntimeException('amazon_listing_get_failed');
        return $r['data'];
    }

    private static function readPricing(SvAmazonClient $client,string $sku): array
    {
        $r=$client->request('GET','/products/pricing/v0/price',[
            'MarketplaceId'=>$client->marketplaceId(),
            'ItemType'=>'Sku','Skus'=>$sku,
        ]);
        $found=[];
        foreach(($r['data']['payload']??[]) as $p){
            if(($p['SellerSKU']??null)===$sku)$found[]=$p;
        }
        if(count($found)!==1)throw new RuntimeException('amazon_effective_offer_not_unique');
        $result=SvAmazonMarginCollector::effectivePriceState($found[0]);
        if(!is_numeric($result['price']??null)
            ||($result['price_source']??'')!=='AMAZON_SPAPI_PRICING')
            throw new RuntimeException('amazon_effective_price_unverified');
        return $result;
    }

    private static function productType(array $listing): string
    {
        $type=trim((string)($listing['summaries'][0]['productType']??
            $listing['productType']??''));
        if($type===''||strlen($type)>100)
            throw new RuntimeException('amazon_product_type_unverified');
        return $type;
    }

    private static function quotePrice(SvAmazonClient $client,array $r,
        int $initialCents,int $targetBps): array
    {
        $marketplace=$client->marketplaceId();
        $price=max((int)round((float)($r['price']??0)*100),$initialCents);
        $cost=$r['current_margin_cost_used']??$r['last_cost']??null;
        if(!is_numeric($cost)||(float)$cost<=0)throw new RuntimeException('unit_cost_unverified');
        $region=trim((string)(getenv('SV_AMAZON_DBA_ORIGIN_REGION')?:''));
        $taxBps=(int)($r['_target_tax_bps']??1400);
        $candidate=$r;
        $candidate['last_cost']=(float)$cost;
        if(($candidate['program']??'')==='DBA_CANDIDATE')
            throw new RuntimeException('dba_candidate_not_verified');
        // Dimension differences are handled by the conservative upper
        // tariff envelope in TargetMarginPublication::quotedMargin().
        // Unknown sources and out-of-program packages remain non-publishable.
        for($i=0;$i<7;$i++){
            $candidate['price']=$price/100;
            $quotes=SvAmazonCurrentFeeEstimates::quote($client,[$candidate],$marketplace);
            $quote=$quotes[(string)$r['seller_sku']]??null;
            if(!is_array($quote)||!isset($quote['commission_cents']))
                throw new RuntimeException('amazon_quote_at_candidate_unavailable');
            $m=SvAmazonTargetMarginPublication::quotedMargin(
                $candidate,$price,$quote,$taxBps,$region!==''?$region:null);
            $profit=(int)$m['current_profit_cents'];
            if($profit*10000 >= $targetBps*$price){
                return ['cents'=>$price,'quote'=>$quote,'margin'=>$m];
            }
            // The next target is *only a candidate*; a new SP-API quote at
            // that exact price is mandatory before any mutation.
            $deficit=$targetBps*$price/10000-$profit;
            $commissionRate=(float)$quote['commission_cents']/$price;
            $available=1-$taxBps/10000-$commissionRate-$targetBps/10000;
            if($available<=0.0001)
                throw new RuntimeException('target_margin_mathematically_unreachable');
            $price+=max(1,(int)ceil($deficit/$available)+1);
            if($price>10000000)
                throw new RuntimeException('price_target_out_of_program_bounds');
        }
        throw new RuntimeException('official_quote_margin_not_converged');
    }

    private static function snapshotItem(PDO $db,array $item): array
    {
        $q=$db->prepare('SELECT r.payload_json FROM amazon_margin_audit_runs r
            INNER JOIN amazon_margin_target_price_requests j ON j.audit_run_id=r.id
            WHERE j.id=? LIMIT 1');
        $q->execute([(int)$item['request_id']]);$raw=$q->fetchColumn();
        if(!is_string($raw)||$raw==='')throw new RuntimeException('audit_snapshot_no_longer_available');
        $snapshot=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        foreach(($snapshot['rows']??[]) as $r){
            if((string)($r['seller_sku']??'')===(string)$item['seller_sku']){
                $r['_target_tax_bps']=(int)($snapshot['tax_bps']??1400);
                $actualId=(int)($r['erp_id']??$r['current_product_link_reference']['erp_id']??0);
                if($actualId!==(int)$item['erp_id'])
                    throw new RuntimeException('erp_identity_changed_after_preview');
                return $r;
            }
        }
        throw new RuntimeException('seller_sku_missing_in_audit_snapshot');
    }

    private static function runItem(PDO $db,array $item,SvAmazonClient $client,string $token): string
    {
        $id=(int)$item['id'];$sku=(string)$item['seller_sku'];
        $marketplace=$client->marketplaceId();$stage=(string)($item['stage']??'queued');
        $target=(int)($item['result_price_cents']??$item['proposal_price_cents']);
        if(in_array($stage,['amazon_submit_started','awaiting_readback'],true)){
            $after=self::readListing($client,$sku);
            $erp=self::readOlist($token);
            $expectedMaximum=isset($item['amazon_maximum_price_cents'])
                ?(int)$item['amazon_maximum_price_cents']:null;
            $priceOk=SvAmazonTargetMarginPublication::listingPricesMatch(
                $after,$marketplace,$target,$expectedMaximum);
            $pricing=self::readPricing($client,$sku);
            $priceOk=$priceOk&&(int)round((float)$pricing['price']*100)===$target;
            $erpOk=is_array($erp) && isset(sveap_parse_table($erp)['exceptions'][(string)$item['erp_id']])
                && (int)round(sveap_parse_table($erp)['exceptions'][(string)$item['erp_id']]['price']*100)===$target;
            if($priceOk&&$erpOk){
                SvAmazonTargetMarginPriceJobs::transition($db,$id,'done','verified_both',$target);
                return 'done';
            }
            $submitted=strtotime((string)($item['submitted_at']??''));
            if($submitted && time()-$submitted>1800){
                SvAmazonTargetMarginPriceJobs::transition($db,$id,'manual_review','readback_timed_out',
                    $target,'amazon_or_erp_readback_not_confirmed_after_30min');
                return 'manual_review';
            }
            SvAmazonTargetMarginPriceJobs::transition($db,$id,'awaiting_readback','awaiting_readback',
                $target,'amazon_or_erp_eventual_consistency');
            return 'awaiting_readback';
        }
        if($stage==='erp_submit_started'){
            // PUT outcome is unknown after a crash; do not repeat blindly.
            SvAmazonTargetMarginPriceJobs::transition($db,$id,'manual_review','uncertain_erp_submit',
                $target,'requires_exact_erp_readback_and_operator_reconciliation');
            return 'manual_review';
        }
        $row=self::snapshotItem($db,$item);
        if(SvAmazonTargetMarginPricePlan::isToolCart($row)){
            // Independent last-mile check for legacy/stale queued work. Never
            // submit a new ERP PUT or Amazon PATCH for an exempt tool cart.
            SvAmazonTargetMarginPriceJobs::transition(
                $db,$id,'manual_review','tool_cart_excluded_no_write',
                $target,'owner_tool_cart_price_exception');
            return 'manual_review';
        }
        if($stage==='erp_verified'){
            // ERP was written and its readback confirmed. Do NOT repeat PUT.
            $erp=self::readOlist($token);
            $original=json_decode((string)($item['original_table_json']??''),true);
            if(!is_array($original)
                ||!SvAmazonTargetMarginPublication::olistPriceVerified(
                    $original,$erp,(int)$item['erp_id'],$target)){
                SvAmazonTargetMarginPriceJobs::transition($db,$id,'manual_review','erp_changed_since_previous_step',
                    $target,'erp_table_no_longer_equals_confirmed_target');
                return 'manual_review';
            }
            return self::submitAmazon($db,$id,$sku,$client,$target,
                $token,$original,(int)$item['erp_id']);
        }
        SvAmazonTargetMarginPriceJobs::transition($db,$id,'running','pricing_quote');
        $proposal=self::quotePrice($client,$row,$target,(int)$item['target_bps']);
        $target=(int)$proposal['cents'];
        // Live Amazon Listing must resolve this exact SKU with a real
        // product type; no fallback inferred from the ERP description.
        $listing=self::readListing($client,$sku);
        $ptype=self::productType($listing);
        // A new quote is not enough; ensure the effective sale price and
        // fulfillment have NOT changed since the selected audit snapshot.
        $live=self::readPricing($client,$sku);
        if((int)round((float)$live['price']*100)!==(int)$item['old_price_cents']
           ||(string)$live['fulfillment_channel']!==(string)($row['fulfillment_channel']??'')){
            SvAmazonTargetMarginPriceJobs::transition($db,$id,'manual_review','stale_effective_offer',
                $target,'amazon_price_or_fulfillment_changed_after_snapshot');
            return 'manual_review';
        }
        $table=self::readOlist($token);
        $erpId=(int)$item['erp_id'];
        $erpSku=(string)($row['erp_sku']??$row['current_product_link_reference']['erp_sku']??'');
        if($erpSku==='')throw new RuntimeException('erp_sku_not_verified');
        $payload=SvAmazonTargetMarginPublication::olistPriceListPayload(
            $table,$erpId,$erpSku,$target);
        // Snapshot and scope are persisted before the first external PUT.
        SvAmazonTargetMarginPriceJobs::transition($db,$id,'running','erp_submit_started',$target,null,null,$table);
        $response=svtop_tiny_request('PUT','/listas-precos/991',$token,$payload);
        if(!in_array((int)($response['status']??0),[200,204],true))
            throw new RuntimeException('olist_price_list_update_not_accepted');
        $updated=self::readOlist($token);
        if(!SvAmazonTargetMarginPublication::olistPriceVerified($table,$updated,$erpId,$target)){
            SvAmazonTargetMarginPriceJobs::transition($db,$id,'manual_review','erp_readback_conflict',
                $target,'olist_991_mismatch_or_unrelated_exception_changed');
            return 'manual_review';
        }
        SvAmazonTargetMarginPriceJobs::transition($db,$id,'running','erp_verified',$target);
        return self::submitAmazon($db,$id,$sku,$client,$target,
            $token,$table,$erpId);
    }

    private static function compensateOlist(string $token,array $original,
        int $erpId,int $newPrice):bool
    {
        try {
            $current=self::readOlist($token);
            if(!SvAmazonTargetMarginPublication::olistPriceVerified(
                $original,$current,$erpId,$newPrice))return false;
            $payload=SvAmazonTargetMarginPublication::olistPriceListRestorePayload($original);
            $response=svtop_tiny_request('PUT','/listas-precos/991',$token,$payload);
            if(!in_array((int)($response['status']??0),[200,204],true))return false;
            $after=self::readOlist($token);
            $before=sveap_parse_table($original);
            $restored=sveap_parse_table($after);
            return $before['percent']===$restored['percent']
                && $before['exceptions']===$restored['exceptions'];
        } catch(Throwable) {return false;}
    }

    private static function submitAmazon(PDO $db,int $itemId,string $sku,
        SvAmazonClient $client,int $priceCents,
        string $tinyToken,array $originalTable,int $erpId):string
    {
        $marketplace=$client->marketplaceId();
        // Reconcile against the freshest official Listings data immediately
        // before PATCH. The earlier snapshot predates the ERP PUT/readback;
        // using it with replace could erase updated B2B offers or metadata.
        $currentListing=self::readListing($client,$sku);
        $productType=self::productType($currentListing);
        $expectedMaximum=SvAmazonTargetMarginPublication::targetMaximumSellerAllowedPriceCents(
            $currentListing,$marketplace,$priceCents);
        $patch=SvAmazonTargetMarginPublication::amazonPricePatch(
            $productType,$marketplace,$priceCents,$currentListing);
        // Persist BEFORE PATCH; on uncertainty only GET/readback is allowed.
        SvAmazonTargetMarginPriceJobs::transition(
            $db,$itemId,'running','amazon_submit_started',$priceCents,
            null,null,null,$expectedMaximum);
        try {
            $result=$client->request('PATCH','/listings/2021-08-01/items/'.
                rawurlencode($client->sellerId()).'/'.rawurlencode($sku),[
                'marketplaceIds'=>$marketplace,'issueLocale'=>'pt_BR'
            ],$patch);
        }catch(SvMarketplaceException $e){
            // HTTP 4xx is a definitive rejection, unlike network/5xx
            // uncertainty; compensate only when ERP still matches our exact
            // isolated write, otherwise leave a manual-review checkpoint.
            if($e->httpStatus>=400&&$e->httpStatus<500){
                $restored=self::compensateOlist(
                    $tinyToken,$originalTable,$erpId,$priceCents);
                SvAmazonTargetMarginPriceJobs::transition($db,$itemId,
                    $restored?'rejected':'manual_review',
                    $restored?'amazon_rejected_erp_compensated':'amazon_rejected_compensation_incomplete',
                    $priceCents,$restored?'provider_refused_erp_restored':'provider_refused_manual_reconcile');
                return $restored?'rejected':'manual_review';
            }
            throw $e;
        }
        $ack=(string)($result['data']['status']??'');
        if(!in_array($ack,['ACCEPTED','VALID'],true)) {
            $restored=self::compensateOlist(
                $tinyToken,$originalTable,$erpId,$priceCents);
            SvAmazonTargetMarginPriceJobs::transition($db,$itemId,
                $restored?'rejected':'manual_review',
                $restored?'amazon_invalid_erp_compensated':'amazon_invalid_erp_reconcile',
                $priceCents,'amazon_listing_not_accepted',
                (string)($result['request_id']??''));
            return $restored?'rejected':'manual_review';
        }
        SvAmazonTargetMarginPriceJobs::transition($db,$itemId,'awaiting_readback',
            'awaiting_readback',$priceCents,null,(string)($result['request_id']??''));
        return 'awaiting_readback';
    }
}
