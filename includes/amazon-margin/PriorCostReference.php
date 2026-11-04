<?php
declare(strict_types=1);

/**
 * A value previously checked against the official ERP cost-history endpoint
 * can be displayed as historical reference during a transient API failure.
 * Never substitute it for a current verified last_cost or certified margin.
 */
final class SvAmazonMarginPriorCostReference
{
    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed> $prior
     * @return array<string,mixed>
     */
    public static function apply(array $current,array $prior,int $priorRunId,
                                  string $currentAt,string $priorAt): array
    {
        $currentTime=strtotime($currentAt.' UTC');
        $priorTime=strtotime($priorAt.' UTC');
        if (!$currentTime||!$priorTime||$priorTime>$currentTime||($currentTime-$priorTime)>48*3600) return $current;
        if (($current['status']??'')!=='erp_or_catalog_read_failed' || ($current['last_cost']??null)!==null
            || isset($current['prior_cost_reference'])) return $current;
        foreach (['seller_sku','asin'] as $key) {
            if (trim((string)($prior[$key]??''))==='' ||
                (string)($current[$key]??'')!==(string)($prior[$key]??'')) return $current;
        }
        if (trim((string)($current['ean']??''))!==trim((string)($prior['ean']??''))) return $current;
        if (!is_numeric($prior['last_cost']??null) || (float)$prior['last_cost']<=0
            || (int)($prior['erp_id']??0)<=0
            || ($prior['last_cost_source']??'')!=='OLIST_TINY_V3_PRODUCT_COST_HISTORY'
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($prior['last_cost_date']??''))) return $current;
        $current['prior_cost_reference']=[
            'cost'=>(float)$prior['last_cost'],
            'cost_date'=>(string)$prior['last_cost_date'],
            'erp_id'=>(int)$prior['erp_id'],
            'erp_sku'=>(string)($prior['erp_sku']??''),
            'verified_run_id'=>$priorRunId,
            'verified_at'=>$priorAt,
            'source'=>'OLIST_TINY_V3_PRODUCT_COST_HISTORY'
        ];
        return $current;
    }

    /** @param list<array<string,mixed>> $priorRuns */
    public static function augment(array $latest,array $priorRuns): array
    {
        if (!$priorRuns || !is_array($latest['snapshot']['rows']??null)) return $latest;
        $currentAt=(string)($latest['generated_at']??'');
        $targets=[];
        foreach ($latest['snapshot']['rows'] as $i=>$row) {
            if (($row['status']??'')==='erp_or_catalog_read_failed' && ($row['last_cost']??null)===null) {
                $targets[(string)($row['seller_sku']??'')]=$i;
            }
        }
        if (!$targets) return $latest;
        foreach ($priorRuns as $run) {
            if (!$targets)break;
            $snapshot=$run['snapshot']??null;
            if (!is_array($snapshot)||!is_array($snapshot['rows']??null))continue;
            foreach ($snapshot['rows'] as $prior) {
                $sku=(string)($prior['seller_sku']??'');
                if (!array_key_exists($sku,$targets))continue;
                $i=$targets[$sku];
                $row=self::apply($latest['snapshot']['rows'][$i],$prior,
                    (int)($run['run_id']??0),$currentAt,(string)($run['generated_at']??''));
                if (isset($row['prior_cost_reference'])) {
                    $latest['snapshot']['rows'][$i]=$row;
                    unset($targets[$sku]);
                }
            }
        }
        return $latest;
    }
}
