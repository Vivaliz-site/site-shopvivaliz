<?php
declare(strict_types=1);

/**
 * Authenticated Olist product-edit -> Anuncios evidence, never an XLS import
 * and never an ERP API price/cost source. Only exact Seller SKU -> ERP product
 * identity may be corroborated. Financial amounts come from CURRENT Olist V3.
 *
 * Capture must contain ALL products reported active by the V3 index. Unreadable
 * or incomplete evidence is a hard failure, not proof of an absent association.
 * Each binding used for a new link must have a verified private screenshot.
 */
final class SvAmazonMarginOlistProductTabCapture
{
    private const CHANNELS = [
        '27473'=>'Amazon',
        '27478'=>'Amazon Onsite',
        '27482'=>'Amazon Classic',
    ];

    public static function path(): string
    {
        return (string)(getenv('SV_AMAZON_OLIST_PRODUCT_TAB_CAPTURE_PATH')
            ?: '/home/ubuntu/.shopvivaliz/runtime/amazon-margin/olist-product-links-current.json');
    }

    public static function load(?string $path=null): array
    {
        $path ??= self::path();
        if (!is_file($path)) return [];
        if (is_link($path) || filesize($path)<100 || filesize($path)>4*1024*1024
            || (fileperms($path)&0007)!==0) {
            throw new RuntimeException('olist_product_tab_capture_file_unsafe');
        }
        $raw=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($raw) || ($raw['schema']??null)!==1
            || ($raw['source']??'')!=='OLIST_ERP_AUTHENTICATED_PRODUCT_EDIT_UI'
            || !is_array($raw['records']??null)
            || ($raw['active_count']??null)!==count($raw['records'])
            || ($raw['active_count']??0)<1 || ($raw['active_count']??0)>10000
            || !is_array($raw['errors']??null) || $raw['errors']!==[]
            || ($raw['channels']??null)!==self::CHANNELS
            || !self::date((string)($raw['input_observed_at']??''))
            || !self::date((string)($raw['observed_at']??''))) {
            throw new RuntimeException('olist_product_tab_capture_incomplete_or_invalid');
        }

        $entries=[];
        foreach($raw['records'] as $id=>$record) {
            if(!is_array($record) || !ctype_digit((string)$id) || (int)$id<1
                || (int)($record['product_id']??0)!==(int)$id
                || !is_string($record['erp_sku']??null)
                || strlen($record['erp_sku'])>120
                || ($record['erp_sku']==='' && !empty($record['bindings']??[]))
                || ($record['url_path']??'')!=='/produtos#edit/'.$id
                || !self::date((string)($record['observed_at']??''))
                || !is_array($record['bindings']??null)
                || count($record['bindings'])>500) {
                throw new RuntimeException('olist_product_tab_record_invalid');
            }
            $erpSku=(string)$record['erp_sku'];
            if(preg_match('/[\x00-\x1f]/',$erpSku)) {
                throw new RuntimeException('olist_product_tab_erp_sku_invalid');
            }
            $sha=(string)($record['screenshot_sha256']??'');
            $screenshotValid=false;
            if($sha!=='') {
                if (!preg_match('/^[a-f0-9]{64}$/',$sha)) {
                    throw new RuntimeException('olist_product_tab_screenshot_hash_invalid');
                }
                $date=(new DateTimeImmutable($record['observed_at']))->setTimezone(
                    new DateTimeZone('UTC'))->format('Ymd');
                $dir=dirname($path).'/evidence/olist-product-tabs';
                $png=$dir.'/product-'.$id.'-'.$date.'.png';
                if (is_link($dir) || is_link($png) || !is_file($png)
                    || filesize($png)<30 || filesize($png)>6*1024*1024
                    || (fileperms($png)&0007)!==0
                    || file_get_contents($png,false,null,0,8)!=="\x89PNG\r\n\x1a\n"
                    || hash_file('sha256',$png)!==$sha) {
                    throw new RuntimeException('olist_product_tab_screenshot_integrity_failed');
                }
                $screenshotValid=true;
            }
            foreach($record['bindings'] as $binding) {
                if(!is_array($binding)
                    || !array_key_exists((string)($binding['channel_id']??''),self::CHANNELS)
                    || (string)($binding['channel']??'')!==
                        self::CHANNELS[(string)$binding['channel_id']]
                    || !preg_match('/^[1-9][0-9]*$/',(string)($binding['mapping_id']??''))
                    || !is_string($binding['seller_sku']??null)
                    || trim($binding['seller_sku'])===''
                    || strlen($binding['seller_sku'])>80
                    || preg_match('/[\x00-\x1f]/',$binding['seller_sku'])) {
                    throw new RuntimeException('olist_product_tab_binding_invalid');
                }
                if(($binding['situacao']??'')!=='A'
                    || (string)($binding['status']??'')!=='1') {
                    // A deactivated ERP ad mapping cannot certify a live link.
                    continue;
                }
                $sku=$binding['seller_sku'];
                $entries[$sku][]=[
                    'id'=>(int)$id,'erp_sku'=>$erpSku,
                    'integration'=>$binding['channel'],
                    'observed_at'=>$record['observed_at'],
                    'screenshot_verified'=>$screenshotValid,
                ];
            }
        }
        return ['source'=>'OLIST_ERP_AUTHENTICATED_PRODUCT_EDIT_UI',
            'observed_at'=>(string)$raw['observed_at'],
            'active_count'=>(int)$raw['active_count'],
            'entries'=>$entries];
    }

    private static function date(string $str): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|\+00:00)$/',$str))
            return false;
        try { new DateTimeImmutable($str); return true; }
        catch(Throwable) { return false; }
    }

    /**
     * @param array<string,mixed> $index
     * @param array<string,list<array<string,mixed>>> $bySku
     */
    public static function lookup(array $index,string $sellerSku,array $bySku,
        ?DateTimeImmutable $now=null): array
    {
        $absent=['status'=>'absent','id'=>null,
            'reason'=>'no_verified_olist_product_tab_binding'];
        $entries=$index['entries'][$sellerSku]??null;
        if (!is_array($entries) || $entries===[]) return $absent;
        $ids=array_values(array_unique(array_map(
            static fn(array $x):int=>(int)$x['id'],$entries)));
        if(count($ids)!==1) {
            return ['status'=>'ambiguous_erp','id'=>null,
                'reason'=>'seller_sku_linked_to_multiple_erp_products_in_product_tabs'];
        }
        $candidate=(string)$entries[0]['erp_sku'];
        $at=$now??new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $observed=new DateTimeImmutable((string)$entries[0]['observed_at']);
        $age=$at->getTimestamp()-$observed->getTimestamp();
        $reference=['status'=>'historical','id'=>null,
            'candidate_erp_sku'=>$candidate,
            'observed_at'=>$entries[0]['observed_at'],
            'integration'=>$entries[0]['integration'],
            'reason'=>'olist_product_tab_capture_stale'];
        if($age<0 || $age>48*3600)return $reference;
        // A linked screenshot for the exact current product is necessary
        // to upgrade a previously unmapped Amazon listing.
        if(!array_reduce($entries,static fn(bool $has,array $x):bool=>
            $has||$x['screenshot_verified'],false)) return $absent;
        $matches=array_values(array_filter($bySku[strtolower($candidate)]??[],
            static fn($x):bool=>is_array($x)
                && (string)($x['sku']??'')===$candidate
                && (int)($x['id']??0)===$ids[0]));
        if(count($matches)!==1) {
            return ['status'=>'ambiguous_erp','id'=>null,
                'candidate_erp_sku'=>$candidate,
                'observed_at'=>$entries[0]['observed_at'],
                'reason'=>'product_tab_not_in_current_unique_olist_v3_index'];
        }
        return ['status'=>'mapped','id'=>$ids[0],
            'erp_sku'=>$candidate,
            'method'=>'OLIST_AUTHENTICATED_PRODUCT_TAB_UI',
            'reason'=>'seller_sku_verified_on_current_olist_product_ads_tab',
            'integration'=>$entries[0]['integration'],
            'observed_at'=>$entries[0]['observed_at']];
    }
}
