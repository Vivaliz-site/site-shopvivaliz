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
    /** Preserve the complete list 991, all unrelated exceptions and promos. */
    public static function olistPriceListPayload(
        array $current,int $erpProductId,string $erpSku,int $priceCents
    ): array {
        sveap_parse_table($current);
        if($erpProductId<=0||$erpSku===''||$priceCents<=0)
            throw new InvalidArgumentException('price_target_invalid');
        $exceptions=$current['excecoes'];
        $found=false;
        foreach($exceptions as &$exception){
            if((int)$exception['idProduto']!==$erpProductId)continue;
            if($found)throw new RuntimeException('price_table_duplicate_erp_id');
            $found=true;
            if((float)($exception['precoPromocional']??0)>0)
                throw new RuntimeException('price_table_promotion_requires_reconciliation');
            $exception['preco']=$priceCents/100;
            // An existing price-list exception retains all ERP metadata.
        }
        unset($exception);
        if(!$found)$exceptions[]=[
            'idProduto'=>$erpProductId,'codigo'=>$erpSku,
            'preco'=>$priceCents/100,'precoPromocional'=>0,
        ];
        return [
            'descricao'=>$current['descricao'],
            'acrescimoDesconto'=>$current['acrescimoDesconto'],
            'excecoes'=>$exceptions,
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
            foreach($original['exceptions'] as $id=>$old){
                if((int)$id===$productId)continue;
                if(!isset($parsed['exceptions'][$id])||$parsed['exceptions'][$id]!==$old)
                    return false;
            }
            return true;
        }catch(Throwable){return false;}
    }

    /** Granular MERGE of the two requested price sub-attributes. */
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
            $max=$offer['maximum_seller_allowed_price'][0]['schedule'][0]['value_with_tax']??null;
            if(is_numeric($max)&&((float)$max)>0
                &&(int)round((float)$max*100)<$cents)
                throw new RuntimeException('amazon_existing_max_price_below_target');
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
                'op'=>'merge',
                'path'=>'/attributes/purchasable_offer',
                'value'=>$offers,
            ]],
        ];
    }

    public static function listingPricesMatch(array $listing,string $marketplaceId,int $cents): bool
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
        return $calculated;
    }
}
