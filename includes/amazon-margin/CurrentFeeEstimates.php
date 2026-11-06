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
            if($sku===''||!in_array($program,['DBA','FBA_ONSITE'],true)
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
        foreach(array_chunk($prices,20) as $batch){
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
            $response=$amazon->request('POST','/products/fees/v0/feesEstimate',[],$requests);
            if($response['status']!==200)throw new RuntimeException('amazon_current_fee_quote_http');
            $items=$response['data'];
            if(!array_is_list($items))throw new RuntimeException('amazon_current_fee_quote_not_list');
            foreach($items as $item){
                $id=$item['FeesEstimateIdentifier']??[];
                if(!is_array($id))continue;
                $sku=(string)($id['IdValue']??'');
                if(!isset($candidates[$sku])||($item['Status']??'')!=='Success')continue;
                $expected=$candidates[$sku];
                $priceData=$id['PriceToEstimateFees']['ListingPrice']??[];
                if(($priceData['CurrencyCode']??'')!=='BRL'
                    ||(int)round(((float)($priceData['Amount']??-1))*100)!==$expected['price_cents']
                    ||(string)($id['MarketplaceId']??'')!==$marketplace)continue;
                $fees=$item['FeesEstimate']['FeeDetailList']??null;
                if(!is_array($fees))continue;
                $commission=0;$hasReferral=false;$valid=true;
                foreach($fees as $f){
                    $type=(string)($f['FeeType']??'');
                    // This is the classic FBA fulfillment fee: DO NOT use it for Onsite.
                    if($type==='FBAFees')continue;
                    if(!in_array($type,['ReferralFee','VariableClosingFee','FixedClosingFee',
                        'ClosingFee','PerItemSellingFee'],true)){$valid=false;break;}
                    $money=$f['FinalFee']??$f['FeeAmount']??[];
                    if(($money['CurrencyCode']??'')!=='BRL'||!is_numeric($money['Amount']??null)
                        ||(float)$money['Amount']<0){$valid=false;break;}
                    $commission+=(int)round((float)$money['Amount']*100,0,PHP_ROUND_HALF_UP);
                    if($type==='ReferralFee')$hasReferral=true;
                }
                if($valid&&$hasReferral)$result[$sku]=[
                    'commission_cents'=>$commission,'price_cents'=>$expected['price_cents']
                ];
            }
            $done+=count($batch);
            if($progress!==null)$progress($done,count($prices));
            // Amazon batch Product Fees usage plan is 0.5 requests/s by default.
            if($done<count($prices))usleep(2250000);
        }
        return $result;
    }
}

