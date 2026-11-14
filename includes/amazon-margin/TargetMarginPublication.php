<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/erp-amazon-price.php';
require_once __DIR__.'/CurrentPriceMargin.php';
require_once __DIR__.'/CurrentFeeEstimates.php';

/**
 * Commercial adapter for the owner-authorized margin publication workflow.
 * It is not invoked by the read-only audit collector or admin preview.
 * Payload builders are pure, for RED/GREEN tests with no provider writes.
 */
final class SvAmazonTargetMarginPublication
{
    /**
     * Olist GET returns excecoes, but its official PUT schema (v3.1.1)
     * requires itens[] with only idProduto, preco and precoPromocional.
     * Never echo the GET response as a PUT body: excecoes/codigo are
     * response fields, not accepted request fields.
     */
    public static function olistPriceListPayload(
        array $current,int $erpProductId,string $erpSku,int $priceCents
    ): array {
        if($erpProductId<=0||$erpSku===''||$priceCents<=0)
            throw new InvalidArgumentException('price_target_invalid');
        return self::olistUpdatePayload($current,$erpProductId,$erpSku,$priceCents);
    }

    /** Restore the exact original price values through the official PUT schema. */
    public static function olistPriceListRestorePayload(array $current):array
    {
        return self::olistUpdatePayload($current,null,null,null);
    }

    private static function olistUpdatePayload(array $current,?int $targetProductId,
        ?string $erpSku,?int $priceCents):array
    {
        sveap_parse_table($current);
        $items=[];$found=false;
        foreach($current['excecoes'] as $exception){
            if(!is_array($exception))throw new RuntimeException('price_table_exception_invalid');
            $id=(int)$exception['idProduto'];
            $regular=(float)$exception['preco'];
            $promo=$exception['precoPromocional']??0;
            if($targetProductId!==null&&$id===$targetProductId){
                if($found)throw new RuntimeException('price_table_duplicate_erp_id');
                $found=true;
                if($erpSku!==null&&isset($exception['codigo'])
                    &&(string)$exception['codigo']!==$erpSku)
                    throw new RuntimeException('price_table_erp_sku_mismatch');
                if(is_numeric($promo)&&(float)$promo>0)
                    throw new RuntimeException('price_table_promotion_requires_reconciliation');
                $regular=$priceCents/100;
            }
            $item=['idProduto'=>$id,'preco'=>$regular];
            if(array_key_exists('precoPromocional',$exception)){
                $item['precoPromocional']=$exception['precoPromocional']===null
                    ?null:(float)$exception['precoPromocional'];
            }
            $items[]=$item;
        }
        if($targetProductId!==null&&!$found){
            $items[]=['idProduto'=>$targetProductId,'preco'=>$priceCents/100,
                'precoPromocional'=>0.0];
        }
        return [
            'descricao'=>(string)$current['descricao'],
            'acrescimoDesconto'=>(float)$current['acrescimoDesconto'],
            'itens'=>$items,
        ];
    }

    public static function olistPriceVerified(
        array $before,array $after,int $productId,int $cents
    ): bool {
        try{
            $parsed=sveap_parse_table($after);
            if(!isset($parsed['exceptions'][(string)$productId])
                ||(int)round($parsed['exceptions'][(string)$productId]['price']*100)!==$cents)
                return false;
            if((float)$parsed['exceptions'][(string)$productId]['promo']>0)return false;
            $original=sveap_parse_table($before);
            if((float)$parsed['percent']!==(float)$original['percent'])return false;
            $expectedCount=count($original['exceptions'])
                +(isset($original['exceptions'][(string)$productId])?0:1);
            if(count($parsed['exceptions'])!==$expectedCount)return false;
            $beforeRows=[];$afterRows=[];
            foreach($before['excecoes'] as $row)$beforeRows[(string)$row['idProduto']]=$row;
            foreach($after['excecoes'] as $row)$afterRows[(string)$row['idProduto']]=$row;
            foreach($original['exceptions'] as $id=>$old){
                if(!isset($parsed['exceptions'][$id]))return false;
                if((int)$id===$productId){
                    if((int)round($parsed['exceptions'][$id]['price']*100)!==$cents
                        ||$parsed['exceptions'][$id]['promo']!==$old['promo'])return false;
                }elseif($parsed['exceptions'][$id]!==$old)return false;
                if(isset($beforeRows[$id]['codigo'])
                    &&(string)($afterRows[$id]['codigo']??'')!==(string)$beforeRows[$id]['codigo'])
                    return false;
            }
            return true;
        }catch(Throwable){return false;}
    }

    /** Round upward to a cent so the configured maximum is never below +10%. */
    private static function tenPercentAboveCents(int $cents):int
    {
        if($cents<=0||$cents>intdiv(PHP_INT_MAX-99,110))
            throw new InvalidArgumentException('amazon_maximum_price_target_invalid');
        return intdiv($cents*110+99,100);
    }

    /** @return array{current:?int,target:?int,entry:?array,schedule_row:?array} */
    private static function maximumPriceDetails(array $offer,int $saleCents):array
    {
        $values=$offer['maximum_seller_allowed_price']??null;
        if($values===null||$values===[])
            return ['current'=>null,'target'=>null,'entry'=>null,'schedule_row'=>null];
        if(!is_array($values)||count($values)!==1||!is_array($values[0]))
            throw new RuntimeException('amazon_maximum_price_shape_unverified');
        $entry=$values[0];
        $schedule=$entry['schedule']??null;
        if(!is_array($schedule)||count($schedule)!==1||!is_array($schedule[0]))
            throw new RuntimeException('amazon_scheduled_maximum_price_requires_reconciliation');
        $row=$schedule[0];
        if(array_key_exists('start_at',$row)||array_key_exists('end_at',$row))
            throw new RuntimeException('amazon_scheduled_maximum_price_requires_reconciliation');
        $raw=$row['value_with_tax']??null;
        if(!is_numeric($raw)||!is_finite((float)$raw)||(float)$raw<=0)
            throw new RuntimeException('amazon_maximum_price_value_unverified');
        $current=(int)round((float)$raw*100,0,PHP_ROUND_HALF_UP);
        if($current<=0)throw new RuntimeException('amazon_maximum_price_value_unverified');
        $target=$current<$saleCents
            ?self::tenPercentAboveCents($saleCents):$current;
        return ['current'=>$current,'target'=>$target,'entry'=>$entry,'schedule_row'=>$row];
    }

    /** Expected Seller Central maximum after applying the owner's price rule. */
    public static function targetMaximumSellerAllowedPriceCents(
        array $listing,string $marketplaceId,int $saleCents
    ):?int {
        if(trim($marketplaceId)===''||$saleCents<=0)
            throw new InvalidArgumentException('amazon_maximum_price_target_invalid');
        $offers=$listing['attributes']['purchasable_offer']??[];
        if(!is_array($offers))throw new RuntimeException('purchasable_offer_not_available');
        $matched=null;
        foreach($offers as $offer){
            if(!is_array($offer)||($offer['marketplace_id']??'')!==$marketplaceId
                ||($offer['audience']??'ALL')!=='ALL')continue;
            if($matched!==null)throw new RuntimeException('duplicate_marketplace_purchasable_offer');
            $matched=$offer;
        }
        if($matched===null)throw new RuntimeException('amazon_existing_offer_not_found');
        return self::maximumPriceDetails($matched,$saleCents)['target'];
    }

    /**
     * Replace only the purchasable_offer attribute using the complete latest
     * GET snapshot, preserving unrelated audiences/offers and all other
     * listing attributes. Amazon's live Listings VALIDATION_PREVIEW rejected
     * op=merge (HTTP 400 InvalidInput) and accepted op=replace (HTTP 200 VALID).
     * The writer supplies an existing real listing before submission.
     */
    public static function amazonPricePatch(
        string $productType,string $marketplaceId,int $cents,
        ?array $existingListing=null
    ): array {
        if(trim($productType)===''||trim($marketplaceId)===''||$cents<=0)
            throw new InvalidArgumentException('amazon_price_patch_invalid');
        $amount=$cents/100;
        $sale=['schedule'=>[['value_with_tax'=>$amount]]];
        $offers=$existingListing===null ? [] :
            ($existingListing['attributes']['purchasable_offer']??[]);
        if(!is_array($offers))throw new RuntimeException('purchasable_offer_not_available');
        $matched=false;
        foreach($offers as &$offer){
            if(!is_array($offer)||($offer['marketplace_id']??'')!==$marketplaceId
                ||($offer['audience']??'ALL')!=='ALL')continue;
            if(($offer['currency']??'')!=='BRL')
                throw new RuntimeException('amazon_currency_not_brl');
            if($matched)throw new RuntimeException('duplicate_marketplace_purchasable_offer');
            $matched=true;
            // Never silently discard promotions or future scheduled prices:
            // the desired margin must describe the effective offer.
            if(isset($offer['discounted_price'])||isset($offer['sale_price']))
                throw new RuntimeException('amazon_promotion_requires_reconciliation');
            $maximum=self::maximumPriceDetails($offer,$cents);
            if($maximum['current']!==null
                &&$maximum['target']!==$maximum['current']){
                $entry=$maximum['entry'];
                $scheduleRow=$maximum['schedule_row'];
                $scheduleRow['value_with_tax']=$maximum['target']/100;
                $entry['schedule']=[$scheduleRow];
                $offer['maximum_seller_allowed_price']=[$entry];
            }
            foreach(['our_price','minimum_seller_allowed_price'] as $field){
                $previous=$offer[$field][0]['schedule']??[];
                if(count($previous)>1||isset($previous[0]['start_at'])
                    ||isset($previous[0]['end_at']))
                    throw new RuntimeException('amazon_scheduled_price_requires_reconciliation');
            }
            $offer['our_price']=[$sale];
            $offer['minimum_seller_allowed_price']=[$sale];
        }
        unset($offer);
        if($existingListing!==null&&!$matched)
            throw new RuntimeException('amazon_existing_offer_not_found');
        if($existingListing===null) {
            $offers=[['currency'=>'BRL','audience'=>'ALL',
                'marketplace_id'=>$marketplaceId,'our_price'=>[$sale],
                'minimum_seller_allowed_price'=>[$sale]]];
        }
        return [
            'productType'=>$productType,
            'patches'=>[[
                'op'=>'replace',
                'path'=>'/attributes/purchasable_offer',
                'value'=>$offers,
            ]],
        ];
    }

    public static function listingPricesMatch(
        array $listing,string $marketplaceId,int $cents,?int $expectedMaximumCents=null
    ): bool
    {
        $offers=$listing['attributes']['purchasable_offer']??[];
        if(!is_array($offers))return false;
        foreach($offers as $offer){
            if(!is_array($offer)||($offer['currency']??'')!=='BRL'
                ||($offer['marketplace_id']??'')!==$marketplaceId
                ||($offer['audience']??'ALL')!=='ALL')continue;
            foreach(['our_price','minimum_seller_allowed_price'] as $field){
                $value=$offer[$field][0]['schedule'][0]['value_with_tax']??null;
                if(!is_numeric($value)||!is_finite((float)$value)
                    ||(int)round((float)$value*100)!==$cents)continue 2;
            }
            $maximum=$offer['maximum_seller_allowed_price']??null;
            if($expectedMaximumCents!==null){
                try{$details=self::maximumPriceDetails($offer,$cents);}
                catch(Throwable){continue;}
                if($details['current']!==$expectedMaximumCents)continue;
            }elseif($maximum!==null&&$maximum!==[]){
                try{$details=self::maximumPriceDetails($offer,$cents);}
                catch(Throwable){continue;}
                if($details['current']!==null&&$details['current']<$cents)continue;
            }
            return true;
        }
        return false;
    }

    /**
     * Validate the official quote *at the new BRL price*, not an extrapolated
     * fee from a prior amount. Used by the controlled writer before PUT/PATCH.
     */
    public static function quotedMargin(
        array $row,int $priceCents,array $quote,int $taxBps,?string $region
    ): array {
        if($priceCents<=0||($quote['price_cents']??null)!==$priceCents
            ||!is_numeric($row['last_cost']??null))
            throw new InvalidArgumentException('official_quote_at_price_missing');
        $atNew=$row;
        $atNew['price']=$priceCents/100;
        $calculated=SvAmazonCurrentPriceMargin::calculate($atNew,$quote,$taxBps,$region);
        if(!isset($calculated['current_profit_cents'],$calculated['current_margin_bps']))
            throw new RuntimeException((string)($calculated['current_margin_status']??'quote_not_calculable'));

        // The Olist and Amazon packages can disagree without either source
        // being forged. The 9% target must use the HIGHER official tariff,
        // including across 0.5/1 kg band boundaries, or remain unpublishable.
        // This does not revise dimensions, shipping programs or product costs.
        $hasAmazonPackage=is_array($row['amazon_package']??null);
        $hasErpPackage=is_array($row['erp_package']??null);
        if($hasAmazonPackage&&$hasErpPackage) {
            // The UI conflict flag has a 0.2 kg/5% tolerance. It is not a
            // tariff-safety flag: even a 20 g difference can cross a fee band.
            $amazon=SvAmazonCurrentPriceMargin::billable($row['amazon_package']);
            $erp=SvAmazonCurrentPriceMargin::billable($row['erp_package']);
            if($amazon===null||$erp===null
                ||!($amazon['eligible']??false)||!($erp['eligible']??false))
                throw new RuntimeException('package_conflict_unresolved');
            $program=(string)($row['program']??'');
            $amazonTariff=SvAmazonCurrentPriceMargin::logisticsCents(
                $program,$priceCents,(float)$amazon['billable_kg'],$region);
            $erpTariff=SvAmazonCurrentPriceMargin::logisticsCents(
                $program,$priceCents,(float)$erp['billable_kg'],$region);
            if($amazonTariff===null||$erpTariff===null)
                throw new RuntimeException('package_conflict_unresolved');
            $tariff=max((int)$calculated['current_logistics_cents'],
                $amazonTariff,$erpTariff);
            $difference=$tariff-(int)$calculated['current_logistics_cents'];
            $calculated['current_logistics_cents']=$tariff;
            $calculated['current_profit_cents']-=$difference;
            $calculated['current_margin_bps']=(int)round(
                $calculated['current_profit_cents']*10000/$priceCents,0,PHP_ROUND_HALF_UP);
            if($difference>0)
                $calculated['current_margin_status']='estimated_dimension_conflict_conservative';
            $calculated['conservative_package_envelope']=true;
            $calculated['conservative_logistics_amazon_cents']=$amazonTariff;
            $calculated['conservative_logistics_erp_cents']=$erpTariff;
        }elseif(($row['current_package_conflict']??false)===true){
            throw new RuntimeException('package_conflict_unresolved');
        }
        return $calculated;
    }
}
