<?php
declare(strict_types=1);

/**
 * Resolve Amazon Seller SKU to an ACTIVE Olist product using verified identities.
 * Never guess a product by similar title or suffix: a wrong cost is worse than
 * an explicitly missing cost. GTIN/EAN is authoritative; an exact SKU match is
 * safe only when it does not contradict the catalog identifier.
 */
final class SvAmazonMarginProductMatch
{
    /**
     * @param array<string,list<array{id:int,sku:string,gtin:string}>> $byEan
     * @param array<string,list<array{id:int,sku:string,gtin:string}>> $bySku
     * @return array{status:string,id:?int,method:?string,reason:string}
     */
    public static function resolve(string|int $sku, ?string $ean, array $byEan, array $bySku): array
    {
        $sku = strtolower(trim((string)$sku));
        $ean = trim((string)$ean);
        $gtinHits = $ean !== '' ? ($byEan[$ean] ?? []) : [];
        $skuHits = $sku !== '' ? ($bySku[$sku] ?? []) : [];
        $uniq = static function(array $hits): array {
            $out=[];
            foreach ($hits as $h) {
                $id=(int)($h['id']??0);
                if ($id>0) $out[$id]=$h;
            }
            return array_values($out);
        };
        $gtinHits=$uniq($gtinHits);
        $skuHits=$uniq($skuHits);
        $direct=count($skuHits)===1 ? $skuHits[0] : null;
        $eanItem=count($gtinHits)===1 ? $gtinHits[0] : null;

        if ($eanItem!==null) {
            if ($direct!==null && (int)$direct['id']!==(int)$eanItem['id']) {
                return ['status'=>'ambiguous_erp','id'=>null,'method'=>null,
                    'reason'=>'seller_sku_conflicts_with_gtin'];
            }
            return ['status'=>'mapped','id'=>(int)$eanItem['id'],'method'=>'EAN',
                'reason'=>'unique_active_gtin'];
        }
        if ($direct!==null && ($ean==='' || (string)($direct['gtin']??'')===$ean)) {
            return ['status'=>'mapped','id'=>(int)$direct['id'],
                'method'=>$ean===''?'SELLER_SKU_EXACT':'SELLER_SKU_AND_EAN',
                'reason'=>'unique_active_exact_sku'];
        }
        if ($ean!=='' && $gtinHits) {
            return ['status'=>'ambiguous_erp','id'=>null,'method'=>null,
                'reason'=>'multiple_active_products_same_gtin'];
        }
        if ($ean!=='' && $direct!==null) {
            return ['status'=>'erp_identity_mismatch','id'=>null,'method'=>null,
                'reason'=>'seller_sku_gtin_mismatch'];
        }
        return ['status'=>'unmapped_erp','id'=>null,'method'=>null,
            'reason'=>$ean===''?'amazon_gtin_absent_no_direct_sku':'no_active_erp_product_with_gtin_or_sku'];
    }
}
