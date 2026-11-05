<?php
declare(strict_types=1);

/**
 * Read-only, independently labelled proof from the AUTHENTICATED Olist Anuncios
 * UI. This is NOT an official XLS export and must never be represented as one.
 * A seller SKU is only resolved after its exact ERP SKU is rechecked in the
 * current API V3 product index and the product detail/history.
 *
 * Evidence remains private, outside the site's current/storage symlink.
 */
final class SvAmazonMarginOlistUiCapture
{
    public static function path(): string
    {
        return (string)(getenv('SV_AMAZON_OLIST_UI_CAPTURE_PATH')
            ?: '/home/ubuntu/.shopvivaliz/runtime/amazon-margin/olist-ui-capture.json');
    }

    public static function load(?string $path = null): array
    {
        $path ??= self::path();
        if (!is_file($path)) return [];
        if (is_link($path) || filesize($path) < 40 || filesize($path) > 4 * 1024 * 1024) {
            throw new RuntimeException('olist_ui_capture_unsafe');
        }
        if ((fileperms($path) & 0007) !== 0) {
            throw new RuntimeException('olist_ui_capture_public_mode');
        }
        $data=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        $integration=(string)($data['scope']['integration']??'');
        $id=(string)($data['scope']['integration_id']??'');
        $when=(string)($data['observed_at']??'');
        if (($data['schema']??null)!==1
            || ($data['source']??'')!=='OLIST_ERP_AUTHENTICATED_UI_CAPTURE'
            || !in_array($integration,['Amazon','Amazon Onsite','Amazon Classic'],true)
            || !preg_match('/^[1-9][0-9]{0,12}$/',$id)
            || ($data['scope']['url_path']??'')!=='/anuncios'
            || !preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?Z$/',$when)
            || !is_array($data['entries']??null)
            || count($data['entries'])<1 || count($data['entries'])>10000
            || !is_array($data['pages']??null)
            || count($data['pages'])<1 || count($data['pages'])>100) {
            throw new RuntimeException('olist_ui_capture_invalid');
        }
        try { $stamp=new DateTimeImmutable($when); }
        catch(Throwable) { throw new RuntimeException('olist_ui_capture_timestamp_invalid'); }
        $slug=strtolower(str_replace(' ','-',$integration));
        $date=$stamp->setTimezone(new DateTimeZone('UTC'))->format('Ymd');
        $evidenceDir=dirname($path).'/evidence';
        if (is_link($evidenceDir) || !is_dir($evidenceDir)) {
            throw new RuntimeException('olist_ui_screenshots_missing');
        }
        $sum=0;
        foreach($data['pages'] as $i=>$page) {
            $n=(int)($page['page']??0);
            $count=(int)($page['count']??0);
            $hash=(string)($page['screenshot_sha256']??'');
            if ($n!==$i+1 || $count<1 || $count>100
                || !preg_match('/^[a-f0-9]{64}$/',$hash)) {
                throw new RuntimeException('olist_ui_capture_pages_invalid');
            }
            $image=$evidenceDir.'/olist-'.$slug.'-p'.$n.'-'.$date.'.png';
            if (is_link($image) || !is_file($image) || filesize($image)>6*1024*1024
                || (string)file_get_contents($image,false,null,0,8)!=="\x89PNG\r\n\x1a\n"
                || hash_file('sha256',$image)!==$hash) {
                throw new RuntimeException('olist_ui_screenshot_integrity_failed');
            }
            $sum+=$count;
        }
        if ($sum!==count($data['entries'])) {
            throw new RuntimeException('olist_ui_capture_incomplete');
        }
        $unique=[];
        foreach($data['entries'] as $entry) {
            if (!is_array($entry))throw new RuntimeException('olist_ui_capture_entry_invalid');
            $sku=(string)($entry['seller_sku']??'');
            $erpSku=(string)($entry['erp_sku']??'');
            if ($sku==='' || strlen($sku)>80 || strlen($erpSku)>120
                || preg_match('/[\\x00-\\x1f]/',$sku.$erpSku) || isset($unique[$sku])) {
                throw new RuntimeException('olist_ui_capture_entry_invalid');
            }
            $unique[$sku]=['erp_sku'=>$erpSku];
        }
        return ['source'=>'OLIST_ERP_AUTHENTICATED_UI_CAPTURE',
            'observed_at'=>$when,'integration'=>$integration,
            'integration_id'=>$id,'entries'=>$unique];
    }

    /**
     * Each real Olist integration has a distinct channel ID and independently
     * signed screenshot evidence. Keep the historical single-Amazon capture
     * compatible, while accepting optional captures of Onsite and Classic.
     * A Seller SKU linked to different products in different ERP channels
     * must NEVER choose one automatically, even if one is newer.
     */
    public static function loadAll(?string $path = null): array
    {
        $primary=$path??self::path();
        $dir=dirname($primary);
        $files=[
            'Amazon'=>$primary,
            'Amazon Onsite'=>$dir.'/olist-ui-capture-amazon-onsite.json',
            'Amazon Classic'=>$dir.'/olist-ui-capture-amazon-classic.json',
        ];
        $merged=[
            'source'=>'OLIST_ERP_AUTHENTICATED_UI_CAPTURE_SET',
            'observed_at'=>null,
            'integration'=>'MULTI',
            'integrations'=>[],
            'entries'=>[],
        ];
        foreach ($files as $channel=>$file) {
            $capture=self::load($file);
            if (!$capture) continue;
            if (($capture['integration']??'')!==$channel) {
                throw new RuntimeException('olist_ui_capture_channel_mismatch');
            }
            $when=(string)$capture['observed_at'];
            $merged['integrations'][]=$channel;
            if ($merged['observed_at']===null
                || strcmp($when,(string)$merged['observed_at'])>0) {
                $merged['observed_at']=$when;
            }
            foreach ($capture['entries'] as $sku=>$source) {
                $candidate=(string)($source['erp_sku']??'');
                $entry=[
                    'erp_sku'=>$candidate,
                    'integration'=>$channel,
                    'observed_at'=>$when,
                ];
                if (!isset($merged['entries'][$sku])) {
                    $merged['entries'][$sku]=$entry;
                    continue;
                }
                $previous=$merged['entries'][$sku];
                if (($previous['erp_sku']??'')!==$candidate
                    || !empty($previous['conflict'])) {
                    $merged['entries'][$sku]['conflict']=true;
                    $merged['entries'][$sku]['reason']='olist_ui_integrations_product_conflict';
                    continue;
                }
                // The same exact product in two channels is corroboration.
                // Prefer the newer observed record for freshness checks,
                // while never using prices or product costs from the UI.
                if (strcmp($when,(string)($previous['observed_at']??''))>0) {
                    $merged['entries'][$sku]=$entry;
                }
            }
        }
        return $merged;
    }

    /**
     * @param array<string,mixed> $capture
     * @param array<string,list<array<string,mixed>>> $bySku
     */
    public static function lookup(array $capture,string $sellerSku,array $bySku,
                                  ?DateTimeImmutable $at=null): array
    {
        $absent=['status'=>'absent','id'=>null,'reason'=>'no_verified_current_olist_ui_mapping'];
        $entry=$capture['entries'][$sellerSku]??null;
        if (!is_array($entry))return $absent;
        $candidate=(string)($entry['erp_sku']??'');
        $observed=(string)($entry['observed_at']??$capture['observed_at']??'');
        $integration=(string)($entry['integration']??$capture['integration']??'');
        if (!empty($entry['conflict'])) {
            return [
                'status'=>'ambiguous_erp',
                'id'=>null,
                'candidate_erp_sku'=>$candidate,
                'observed_at'=>$observed,
                'integration'=>$integration,
                'reason'=>'olist_ui_integrations_product_conflict',
            ];
        }
        $stale=['status'=>'historical','id'=>null,
            'candidate_erp_sku'=>$candidate,'observed_at'=>$observed,
            'integration'=>$integration,
            'reason'=>'historical_olist_ui_capture_requires_refresh'];
        if($candidate==='')return ['status'=>'ambiguous_erp','id'=>null,
            'reason'=>'olist_ui_listing_not_related_to_product'];
        try {$timestamp=new DateTimeImmutable($observed);}
        catch(Throwable){return $stale;}
        $at ??= new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $age=$at->getTimestamp()-$timestamp->getTimestamp();
        if($age<0 || $age>48*3600)return $stale;
        $matches=array_values(array_filter($bySku[strtolower($candidate)]??[],
            static fn($p):bool=>is_array($p)
                && (string)($p['sku']??'')===$candidate && (int)($p['id']??0)>0));
        if(count($matches)!==1)return ['status'=>'ambiguous_erp','id'=>null,
            'reason'=>'ui_product_sku_not_unique_in_live_erp',
            'candidate_erp_sku'=>$candidate,'observed_at'=>$observed];
        return ['status'=>'mapped','id'=>(int)$matches[0]['id'],
            'erp_sku'=>$candidate,'method'=>'OLIST_AUTHENTICATED_UI',
            'reason'=>'seller_sku_verified_in_live_olist_ads_ui',
            'integration'=>$integration,'observed_at'=>$observed];
    }

    /**
     * Conservative precedence: a modern UI binding cannot silently rewrite
     * financial identity established by order or GTIN evidence. Any conflict
     * must invalidate certification until separately reconciled.
     */
    public static function decision(array $current,?array $product,array $ui): string
    {
        $state=(string)($ui['status']??'absent');
        if ($state==='absent')return 'none';
        if ($state==='ambiguous_erp')return 'hold';
        $priorSku=(string)($product['erp_sku']??'');
        $uiSku=(string)($ui['erp_sku']??$ui['candidate_erp_sku']??'');
        if ($state==='historical') {
            return $priorSku!=='' && $uiSku!=='' && $priorSku!==$uiSku
                ? 'hold' : 'reference_only';
        }
        if ($state!=='mapped')return 'hold';
        if ($priorSku!=='' && $priorSku!==$uiSku)return 'hold';
        if (($current['status']??'')==='mapped' && $product!==null) {
            if((int)($current['id']??0)!==(int)($ui['id']??0))return 'hold';
            return 'verified_same';
        }
        if (($current['reason']??'')==='historical_amazon_orders_disagree_on_product') {
            return 'hold';
        }
        return 'use_ui';
    }
}
