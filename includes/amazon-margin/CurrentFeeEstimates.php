<?php
declare(strict_types=1);

/**
 * Pricing quote only: read-only POST to Amazon Product Fees API.
 * Only the marketplace selling fee is reused; FBAFees means classic FBA
 * and is explicitly NOT charged for FBA Onsite or DBA.
 * https://developer-docs.amazon/sp-api/reference/getmyfeesestimates
 */
final class SvAmazonCurrentFeeEstimates
{
    /** Verify seller SKU, BRL, marketplace, current price and permissible selling fees. */
    public static function normalize(array $item,array $expected,string $marketplace): ?array
    {
        $id=$item['FeesEstimateIdentifier']??[];
        if(!is_array($id)||($item['Status']??'')!=='Success'
            ||(string)($id['IdValue']??'')!==(string)$expected['sku'])return null;
        $priceData=$id['PriceToEstimateFees']['ListingPrice']??[];
        if(($priceData['CurrencyCode']??'')!=='BRL'
            ||!is_numeric($priceData['Amount']??null)
            ||(int)round((float)$priceData['Amount']*100)!==$expected['price_cents']
            ||(string)($id['MarketplaceId']??'')!==$marketplace)return null;
        $fees=$item['FeesEstimate']['FeeDetailList']??null;
        if(!is_array($fees))return null;
        $commission=0;$hasReferral=false;
        foreach($fees as $f){
            $type=(string)($f['FeeType']??'');
            // Classic FBA fulfillment estimates must NEVER replace FBA Onsite tariffs.
            if($type==='FBAFees')continue;
            if(!in_array($type,['ReferralFee','VariableClosingFee','FixedClosingFee',
                'ClosingFee','PerItemSellingFee'],true))return null;
            $money=$f['FinalFee']??$f['FeeAmount']??[];
            if(($money['CurrencyCode']??'')!=='BRL'||!is_numeric($money['Amount']??null)
                ||(float)$money['Amount']<0)return null;
            $commission+=(int)round((float)$money['Amount']*100,0,PHP_ROUND_HALF_UP);
            if($type==='ReferralFee')$hasReferral=true;
        }
        return $hasReferral?[
            'commission_cents'=>$commission,'price_cents'=>$expected['price_cents']
        ]:null;
    }

    /**
     * Retry ONLY the read-only Amazon Product Fees quote POST after HTTP 429.
     * Never apply this retry to commerce writes. Backoff is bounded and tests
     * inject a no-sleep callback to verify the exact number of attempts.
     */
    public static function retryQuoteOnQuota(callable $request,?callable $pause=null): array
    {
        $delays=[0,2500000,6000000,15000000];
        foreach($delays as $attempt=>$delay){
            if($attempt>0){
                if($pause!==null)$pause($delay);
                else usleep($delay);
            }
            try{
                $response=$request();
                if(!is_array($response))throw new UnexpectedValueException('fee_quote_invalid_response');
                return $response;
            }catch(SvMarketplaceException $e){
                if($e->httpStatus!==429 || $attempt===count($delays)-1)throw $e;
            }
        }
        throw new LogicException('fee_quote_unreachable');
    }

    /**
     * @return array<string, array{commission_cents:int,price_cents:int}>
     */
    public static function quote(SvAmazonClient $amazon,array $rows,string $marketplace,?callable $progress=null):array
    {
        $candidates=[];
        foreach($rows as $r){
            if(!is_array($r))continue;
            $sku=(string)($r['seller_sku']??'');
            $program=(string)($r['program']??'');
            $price=$r['price']??null;
            $status=$r['amazon_status']??[];
            if($sku===''||!in_array($program,['DBA','DBA_CANDIDATE','FBA_ONSITE'],true)
                ||!is_numeric($price)||!is_finite((float)$price)||(float)$price<=0
                ||!is_numeric($r['last_cost']??null)
                ||!is_array($status)||!in_array('BUYABLE',$status,true))continue;
            $candidates[$sku]=[
                'sku'=>$sku,'price_cents'=>(int)round((float)$price*100),
                'is_fba'=>$program==='FBA_ONSITE'
            ];
        }
        $prices=array_values($candidates);
        $result=[];$done=0;
        foreach(array_chunk($prices,5) as $batch){
            $requests=[];
            foreach($batch as $p){
                $requests[]=[
                    'IdType'=>'SellerSKU','IdValue'=>$p['sku'],
                    'FeesEstimateRequest'=>[
                        'MarketplaceId'=>$marketplace,
                        'PriceToEstimateFees'=>['ListingPrice'=>[
                            'CurrencyCode'=>'BRL','Amount'=>$p['price_cents']/100]],
                        'Identifier'=>'SV_MARGIN_CURRENT_'.hash('sha256',$p['sku'].'|'.$p['price_cents']),
                        'IsAmazonFulfilled'=>$p['is_fba'],
                    ]
                ];
            }
            $response=self::retryQuoteOnQuota(static fn():array=>$amazon->request('POST','/products/fees/v0/feesEstimate',[],$requests));
            if($response['status']!==200)throw new RuntimeException('amazon_current_fee_quote_http');
            $items=$response['data'];
            if(!array_is_list($items))throw new RuntimeException('amazon_current_fee_quote_not_list');
            foreach($items as $item){
                $sku=(string)($item['FeesEstimateIdentifier']['IdValue']??'');
                if(!isset($candidates[$sku]))continue;
                $normalized=self::normalize($item,$candidates[$sku],$marketplace);
                if($normalized!==null)$result[$sku]=$normalized;
            }
            $done+=count($batch);
            if($progress!==null)$progress($done,count($prices));
            // Amazon batch Product Fees usage plan is 0.5 requests/s by default.
            if($done<count($prices))usleep(2250000);
        }
        return $result;
    }
}

