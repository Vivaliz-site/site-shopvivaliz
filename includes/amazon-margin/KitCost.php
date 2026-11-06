<?php
declare(strict_types=1);

/**
 * Resolve the cost of ONE Amazon sellable offer, not of one underlying
 * component. The Olist product can be a ready-made kit (tipo K) or a simple
 * part (tipo S) that is sold as multiple pieces under an Amazon ASIN.
 *
 * Source of units: Amazon listing title, independently checked against the
 * current Olist product's type and description. Never infer kit quantity from
 * the Seller SKU alone, millimetres, centimetres, or money amounts.
 */
final class SvAmazonMarginKitCost
{
    public static function quantity(string $title): ?int
    {
        $title=trim($title);
        if ($title==='') return null;
        foreach ([
            '/\bkit(?:\s+(?:de|com))?\s+([2-9]\d?)\s*(?=[\p{L}\d])/iu',
            '/\b(?:pack|pacote|conjunto|jogo)(?:\s+(?:de|com))?\s+([2-9]\d?)\s*(?=[\p{L}\d])/iu',
            '/\b([2-9]\d?)\s*(?:unidades?|unid(?:ades)?|un\.?|p[cç]s|pe[cç]as?|pcs)\b/iu',
        ] as $pattern) {
            if (preg_match($pattern,$title,$m)===1) {
                $n=(int)$m[1];
                if ($n>=2 && $n<=99) return $n;
            }
        }
        return null;
    }

    private static function mixedComponents(string $title): bool
    {
        return preg_match('/\b(?:com|c\/)\s*(?:freio|trava)\b.*?\b(?:sem|s\/)\s*(?:freio|trava)?\b/iu',$title)===1
            || preg_match('/\b(?:sem|s\/)\s*(?:freio|trava)\b.*?\b(?:com|c\/)\s*(?:freio|trava)\b/iu',$title)===1
            || preg_match('/\b(?:duas|dois|2)\s+(?:com|c\/)\s*(?:freio|trava)\b.*?\b(?:duas|dois|2)\s+(?:sem|s\/)/iu',$title)===1;
    }

    /**
     * @param array<string,mixed> $product The exact current Olist V3 product detail.
     * @return array{status:string,cost:?float,base_cost:?float,quantity:?int,multiplier:int,basis:string,reason:?string}
     */
    public static function resolve(string $amazonTitle,array $product): array
    {
        $cost=$product['last_cost']??null;
        $type=strtoupper(trim((string)($product['erp_product_type']??'')));
        $description=trim((string)($product['erp_description']??''));
        $offerCount=self::quantity($amazonTitle);
        $erpCount=self::quantity($description);
        $isKitTitle=preg_match('/\b(?:kit|conjunto|pack|pacote|jogo)\b/iu',$amazonTitle)===1;
        $isKitDescription=preg_match('/\b(?:kit|conjunto|pack|pacote|jogo)\b/iu',$description)===1;
        $base=is_numeric($cost) && is_finite((float)$cost) && (float)$cost>0
            ? (float)$cost : null;

        $result=['status'=>'confirmed','cost'=>$base,'base_cost'=>$base,
            'quantity'=>$offerCount??$erpCount??1,'multiplier'=>1,
            'basis'=>'ERP_UNIT_OR_BUNDLE_COST','reason'=>null];
        if($base===null) {
            return array_replace($result,['status'=>'unverified','cost'=>null,
                'basis'=>'MISSING_ERP_COST','reason'=>'erp_current_cost_missing']);
        }
        if($type==='K') {
            // In Olist, tipo K has a per-kit cost, not a per-component cost.
            // Keep it as ONE saleable unit. The BOM is an independent check;
            // do not multiply the recorded complete-kit cost by BOM quantity.
            if($erpCount!==null && $offerCount!==null && $erpCount!==$offerCount) {
                return array_replace($result,['status'=>'unverified','cost'=>null,
                    'basis'=>'KIT_COMPOSITION_CONFLICT',
                    'reason'=>'amazon_pack_size_differs_from_erp_kit']);
            }
            return array_replace($result,['basis'=>'ERP_COMPLETE_KIT_COST']);
        }
        if($type!=='S' && $type!=='P') {
            return array_replace($result,['status'=>'unverified','cost'=>null,
                'basis'=>'UNKNOWN_ERP_PRODUCT_TYPE','reason'=>'erp_product_type_not_verified']);
        }
        if ($offerCount===null) {
            // 'Kit mecanismo' is not evidence for how many separate parts
            // are sold. A simple ERP component must not claim a certified
            // margin for a bundle of unknown size.
            if($isKitTitle && !$isKitDescription) {
                return array_replace($result,['status'=>'unverified','cost'=>null,
                    'quantity'=>null,'basis'=>'UNVERIFIED_KIT_QUANTITY',
                    'reason'=>'amazon_kit_size_not_verified_against_erp_product']);
            }
            return $result;
        }

        if ($erpCount!==null) {
            if($erpCount!==$offerCount) {
                return array_replace($result,['status'=>'unverified','cost'=>null,
                    'basis'=>'KIT_QUANTITY_CONFLICT',
                    'reason'=>'amazon_pack_size_differs_from_erp_description']);
            }
            return array_replace($result,['basis'=>'ERP_ALREADY_PRICED_AS_COMPLETE_PACK']);
        }
        if ($isKitDescription) {
            return array_replace($result,['status'=>'unverified','cost'=>null,
                'basis'=>'ERP_KIT_SIZE_NOT_PROVEN',
                'reason'=>'erp_description_has_kit_without_verifiable_quantity']);
        }
        if(self::mixedComponents($amazonTitle)) {
            return array_replace($result,['status'=>'unverified','cost'=>null,
                'basis'=>'MIXED_BUNDLE_COMPONENTS',
                'reason'=>'mixed_component_kit_requires_bill_of_materials']);
        }

        $cents=(int)round($base*100,0,PHP_ROUND_HALF_UP);
        if($cents<=0 || $cents>100000000 || $offerCount>99) {
            return array_replace($result,['status'=>'unverified','cost'=>null,
                'basis'=>'KIT_COST_OUT_OF_RANGE','reason'=>'kit_unit_cost_unverified']);
        }
        return array_replace($result,['cost'=>round(($cents*$offerCount)/100,2),
            'multiplier'=>$offerCount,'basis'=>'OLIST_COMPONENT_COST_X_AMAZON_PACK_UNITS']);
    }
}
