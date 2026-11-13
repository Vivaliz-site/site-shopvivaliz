<?php
declare(strict_types=1);

require_once __DIR__.'/CurrentPriceMargin.php';

/**
 * Non-mutating price plan for a selected Amazon Seller SKU.
 *
 * This is a simulation using the CURRENT SP-API referral/closing fee quote
 * as the rate at a different selling price. It is NOT an approval or a quote
 * at the proposed selling price; publication requires that fresh quote,
 * current ERP 991 + Amazon Listings/Pricing readback, and the existing
 * commercial workflow. Keep historical realized margin independent.
 */
final class SvAmazonTargetMarginPricePlan
{
    public static function targetBps(mixed $percent): int
    {
        if (!is_int($percent) && !is_float($percent) && !is_string($percent))
            throw new InvalidArgumentException('margin_target_invalid');
        if (!is_numeric($percent) || !is_finite((float)$percent))
            throw new InvalidArgumentException('margin_target_invalid');
        $p=(float)$percent;
        if ($p<=0 || $p>=100)throw new InvalidArgumentException('margin_target_out_of_range');
        return (int)round($p*100,0,PHP_ROUND_HALF_UP);
    }

    /** @param array<string,mixed> $snapshot
     *  @param list<string> $skus
     *  @return array<string,mixed>
     */
    public static function prepare(array $snapshot,array $skus,int $targetBps,?string $region=null): array
    {
        if ($targetBps<1||$targetBps>=10000)throw new InvalidArgumentException('margin_target_out_of_range');
        $all=is_array($snapshot['rows']??null)?$snapshot['rows']:[];
        $indexed=[];$erpProducts=[];
        foreach ($all as $r) {
            if (!is_array($r))continue;
            $sku=(string)($r['seller_sku']??'');
            if ($sku==='')continue;
            $indexed[$sku]=$r;
            $id=self::erpId($r);
            if ($id!==null)$erpProducts[$id][]=$sku;
        }
        $selected=array_values(array_unique($skus));
        $rows=[];$ready=0;$pending=0;$grossCents=0;
        foreach ($selected as $sku) {
            $r=$indexed[$sku]??null;
            if (!is_array($r)) {
                $rows[]=self::empty($sku,'sku_not_in_latest_audit');
                ++$pending;
                continue;
            }
            $planned=self::planRow($r,$targetBps,$region);
            $productId=self::erpId($r);
            $planned['erp_id']=$productId;
            $planned['erp_sku']=(string)($r['erp_sku']??$r['current_product_link_reference']['erp_sku']??'');
            $planned['related_seller_skus']=$productId===null?[]:array_values(array_filter(
                $erpProducts[$productId]??[],static fn($other):bool=>$other!==$sku
            ));
            $planned['related_selected_skus']=array_values(array_intersect(
                $planned['related_seller_skus'],$selected
            ));
            $planned['related_unselected_skus']=array_values(array_diff(
                $planned['related_seller_skus'],$selected
            ));
            if ($planned['state']==='simulated') {
                $ready++;
                $grossCents+=(int)$planned['proposed_price_cents'];
            } else $pending++;
            $rows[]=$planned;
        }
        $byProduct=[];
        foreach ($rows as $i=>$r) {
            if ($r['state']!=='simulated'||$r['erp_id']===null)continue;
            $byProduct[(string)$r['erp_id']][$r['proposed_price_cents']][]=$i;
        }
        foreach ($byProduct as $prices) {
            if (count($prices)<2)continue;
            foreach ($prices as $ids)foreach ($ids as $i) {
                $rows[$i]['price_list_shared_product_conflict']=true;
                $rows[$i]['notices'][]='skus_mesmo_produto_erp_precos_diferentes';
            }
        }
        return [
            'target_margin_bps'=>$targetBps,'target_percent'=>$targetBps/100,
            'currency'=>'BRL','erp_price_list_id'=>991,
            'amazon_price_fields'=>['our_price','minimum_seller_allowed_price'],
            'method'=>'current_fee_rate_estimate_only','authoritative_quote_at_target'=>false,
            'modifies_prices'=>false,'selected'=>count($selected),
            'simulated'=>$ready,'pending'=>$pending,
            'total_simulated_prices_cents'=>$grossCents,'rows'=>$rows
        ];
    }

    /** Determine an exact current ERP ID; never guess from the title. */
    private static function erpId(array $row): ?int
    {
        $id=(int)($row['erp_id']??0);
        if ($id>0)return $id;
        $ref=$row['current_product_link_reference']??null;
        if (is_array($ref)&&($ref['status']??'')==='offer_cost_verified'
            && ($ref['source']??'')==='OLIST_AUTHENTICATED_PRODUCT_TAB_UI_X_CURRENT_OLIST_V3'
            && (int)($ref['erp_id']??0)>0)return (int)$ref['erp_id'];
        return null;
    }

    private static function empty(string $sku,string $reason): array
    {
        return ['seller_sku'=>$sku,'state'=>'pending','reason'=>$reason,
            'proposed_price_cents'=>null,'minimum_price_cents'=>null,
            'notices'=>[],'erp_id'=>null,'related_seller_skus'=>[]];
    }

    private static function planRow(array $r,int $targetBps,?string $region): array
    {
        $sku=(string)($r['seller_sku']??'');
        $result=self::empty($sku,'not_eligible_for_simulation');
        $result['title']=(string)($r['title']??'');
        $result['asin']=(string)($r['asin']??'');
        $result['program']=(string)($r['program']??'');
        $result['current_margin_bps']=$r['current_margin_bps']??null;
        $result['current_price_cents']=null;
        $result['notices']=[];
        if (!in_array('BUYABLE',is_array($r['amazon_status']??null)?$r['amazon_status']:[],true))
            return self::fail($result,'offer_not_buyable');
        $price=$r['price']??null;
        if (!is_numeric($price) || !is_finite((float)$price) || (float)$price<=0)
            return self::fail($result,'effective_price_unverified');
        $current=(int)round((float)$price*100,0,PHP_ROUND_HALF_UP);
        $result['current_price_cents']=$current;
        $cost=$r['current_margin_cost_used']??$r['last_cost']??null;
        if (!is_numeric($cost)||!is_finite((float)$cost)||(float)$cost<=0)
            return self::fail($result,'unit_cost_unverified');
        $costCents=(int)round((float)$cost*100,0,PHP_ROUND_HALF_UP);
        $commission=$r['current_commission_cents']??null;
        if (!is_numeric($commission)||!is_finite((float)$commission)||(int)$commission<0)
            return self::fail($result,'current_fee_quote_unavailable');
        $program=(string)($r['program']??'');
        if ($program==='DBA_CANDIDATE') {
            $result['notices'][]='programa_dba_ainda_candidato';
            $program='DBA';
        }
        if(!in_array($program,['DBA','FBA_ONSITE'],true))
            return self::fail($result,'fulfillment_program_unverified');
        if(($r['current_package_conflict']??false)===true)
            $result['notices'][]='dimensoes_erp_amazon_divergentes';
        if(($r['status']??'')==='ambiguous_erp')
            $result['notices'][]='identidade_historica_divergente_do_erp_atual';
        // Margem negativa calculada representa prejuizo, nao dado ausente.
        $bps=$r['current_margin_bps']??null;
        if(!is_numeric($bps)||!is_finite((float)$bps))
            return self::fail($result,'current_margin_not_calculated');
        $taxBps=(int)($r['_target_tax_bps']??1400);
        $weight=$r['current_billable_kg']??null;
        $kg=is_numeric($weight)&&is_finite((float)$weight)?(float)$weight:null;
        // A provisional owner preview must show the upper known tariff before
        // the approved worker's exact-fee quote. Do not silently raise a price
        // far above the signed preview because the ERP package is larger.
        // The worker still refuses unknown or ineligible package sources.
        if(($r['current_package_conflict']??false)===true) {
            $amazon=is_array($r['amazon_package']??null)
                ? SvAmazonCurrentPriceMargin::billable($r['amazon_package']) : null;
            $erp=is_array($r['erp_package']??null)
                ? SvAmazonCurrentPriceMargin::billable($r['erp_package']) : null;
            if($amazon!==null&&$erp!==null
                &&($amazon['eligible']??false)&&($erp['eligible']??false)) {
                $kg=max((float)($kg??0),(float)$amazon['billable_kg'],
                    (float)$erp['billable_kg']);
                $result['notices'][]='tarifa_conservadora_entre_embalagens_amazon_olist';
            }else{
                $result['notices'][]='embalagem_divergente_requer_conciliacao';
            }
        }
        $currentCommission=(int)$commission;
        // At 0% margin no price may be inferred from cost alone: use the last
        // official SP-API fee quote as an *explicitly provisional* rate.
        $calculator=static function(int $p)use($current,$currentCommission,$costCents,
            $taxBps,$program,$kg,$region): ?array {
            $freight=SvAmazonCurrentPriceMargin::logisticsCents($program,$p,$kg,$region);
            if($freight===null)return null;
            $fee=(int)round($currentCommission*$p/$current,0,PHP_ROUND_HALF_UP);
            $tax=(int)round($taxBps*$p/10000,0,PHP_ROUND_HALF_UP);
            $profit=$p-$costCents-$fee-$freight-$tax;
            return ['price_cents'=>$p,'cost_cents'=>$costCents,
                'commission_estimate_cents'=>$fee,'logistics_cents'=>$freight,
                'tax_cents'=>$tax,'wms_cents'=>0,'profit_estimate_cents'=>$profit,
                'margin_estimate_bps'=>(int)round(10000*$profit/$p,0,PHP_ROUND_HALF_UP),
                'margin_estimate_percent'=>round(100*$profit/$p,4)];
        };
        $limits=[2999,4999,7899,9999,11999,14999,19999,99999,499999,2499999,10000000];
        $low=$current;
        $proposal=null;
        foreach($limits as $end){
            if($end<$low)continue;
            $high=$calculator($end);
            if($high===null)continue;
            if($high['profit_estimate_cents']*10000<$targetBps*$end){
                $low=$end+1;continue;
            }
            // The cost model is monotone inside each tariff range, not
            // necessarily across a fee-band threshold.
            $start=$low;$stop=$end;
            while($start<$stop){
                $mid=$start+intdiv($stop-$start,2);
                $midResult=$calculator($mid);
                if($midResult!==null
                    && $midResult['profit_estimate_cents']*10000 >= $targetBps*$mid)
                    $stop=$mid;
                else $start=$mid+1;
            }
            // Monetary rounding may cause one-cent oscillations; inspect
            // the immediate threshold neighborhood before selecting.
            $candidate=$calculator($start);
            if($candidate===null)continue;
            $floor=max($low,$start-96);
            for($probe=$floor;$probe<$start;$probe++){
                $m=$calculator($probe);
                if($m!==null && $m['profit_estimate_cents']*10000 >= $targetBps*$probe){
                    $candidate=$m;break;
                }
            }
            $proposal=$candidate;break;
        }
        if($proposal===null)return self::fail($result,'target_not_solvable_with_known_tariff');
        $result=array_merge($result,$proposal);
        $result['state']='simulated';
        $result['reason']=null;
        $result['proposed_price_cents']=$proposal['price_cents'];
        $result['minimum_price_cents']=$proposal['price_cents'];
        $result['change_cents']=$proposal['price_cents']-$current;
        $result['target_margin_bps']=$targetBps;
        return $result;
    }

    private static function fail(array $row,string $reason): array
    {
        $row['reason']=$reason;
        return $row;
    }
}
