<?php
declare(strict_types=1);

/**
 * Conservative read-only Olist ad-to-product index from a RECENT admin export.
 * An old export is diagnostic reference only; it must never certify margin.
 */
final class SvAmazonMarginOlistAdsIndex
{
    public static function path(): string
    {
        return (string)(getenv('SV_AMAZON_OLIST_ADS_INDEX_PATH')
            ?: '/home/ubuntu/shopvivaliz-deploy/shared/storage/amazon-margin/olist-ads-index.json');
    }

    public static function load(?string $path = null): array
    {
        $path ??= self::path();
        if (!is_file($path)) return [];
        if (is_link($path) || filesize($path) > 4 * 1024 * 1024) {
            throw new RuntimeException('olist_ads_index_unsafe');
        }
        $data=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema']??null)!==1
            || ($data['source']??'')!=='OLIST_ERP_ANUNCIOS_EXPORT'
            || !is_array($data['entries']??null)) {
            throw new RuntimeException('olist_ads_index_invalid');
        }
        return $data;
    }

    /**
     * @param array<string,mixed> $index
     * @param array<string,list<array<string,mixed>>> $bySku current LIVE ERP index
     * @return array<string,mixed>
     */
    public static function lookup(array $index,string $sellerSku,array $bySku,
                                  ?DateTimeImmutable $at = null): array
    {
        $missing=['status'=>'absent','id'=>null,'method'=>null,
            'reason'=>'no_current_olist_ad_export_mapping'];
        if (!$index || !is_array($index['entries']??null)) return $missing;
        $entry=$index['entries'][$sellerSku]??null;
        if (!is_array($entry))return $missing;
        $candidate=(string)($entry['erp_sku']??'');
        $observed=(string)($entry['observed_at']??'');
        $integrations=$entry['integrations']??[];
        $allowed=['Amazon','Amazon Onsite','Amazon Classic'];
        if (!is_array($integrations) || !$integrations
            || count(array_diff($integrations,$allowed))>0) {
            return $missing;
        }
        $known=['status'=>'historical','id'=>null,'method'=>null,
            'reason'=>'historical_olist_ad_export_requires_fresh_export',
            'candidate_erp_sku'=>$candidate,
            'observed_at'=>$observed,'integration'=>implode(', ',$integrations)];
        if (($entry['status']??'')!=='unique' || $candidate==='') {
            return ['status'=>'ambiguous_erp','id'=>null,'method'=>null,
                'reason'=>'conflicting_olist_ad_export_product_skus',
                'observed_at'=>$observed,'integration'=>implode(', ',$integrations)];
        }
        try {
            $timestamp=new DateTimeImmutable($observed);
        } catch (Throwable) {
            return $known;
        }
        $at ??= new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $age=$at->getTimestamp()-$timestamp->getTimestamp();
        if (($entry['fresh']??false)!==true || $age<0 || $age>48*3600) return $known;
        $candidates=array_values(array_filter(
            $bySku[strtolower($candidate)]??[],
            static fn($p):bool=>is_array($p) && (string)($p['sku']??'')===$candidate
                && (int)($p['id']??0)>0
        ));
        if (count($candidates)!==1) {
            return ['status'=>'ambiguous_erp','id'=>null,'method'=>null,
                'reason'=>'export_product_sku_not_unique_in_live_erp',
                'observed_at'=>$observed,'integration'=>implode(', ',$integrations)];
        }
        return ['status'=>'mapped','id'=>(int)$candidates[0]['id'],
            'method'=>'OLIST_ADS_EXPORT',
            'reason'=>'seller_sku_linked_by_recent_olist_anuncios_export',
            'integration'=>implode(', ',$integrations),
            'observed_at'=>$observed,'evidence_count'=>1];
    }
}
