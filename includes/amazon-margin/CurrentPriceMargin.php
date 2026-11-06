<?php
declare(strict_types=1);

/**
 * Forward-looking margin from the CURRENT Amazon listing price, never
 * historical Shipment proceeds. Monetary results use integer BRL cents.
 * Sources:
 * https://sellercentral.amazon.com.br/help/hub/reference/external/GJLSAYQ5DXWXEEGU
 * https://sellercentral.amazon.com.br/gp/help/external/GSCSJT23SEAKNXAN
 * https://venda.amazon.com.br/cresca/dba
 * 2025-08 fee schedule as applicable in 2026. The Jun-Sep 2026
 * temporary discount is NOT included. FBA Onsite WMS rate = BRL 0.
 */
final class SvAmazonCurrentPriceMargin
{
    private const KG=[0.25,0.5,1,2,3,4,5,6,7,8,9,10];
    private const BETWEEN_79_200=[
        [1195,1285,1345,1400,1495,1615,1700,2500,2600,2700,2800,3950],
        [1395,1500,1570,1635,1745,1885,1990,3000,3100,3200,3300,4600],
        [1595,1715,1795,1875,1995,2155,2275,3400,3500,3600,3700,5275],
        [1795,1930,2020,2110,2240,2420,2560,3800,3900,4000,4100,5900],
    ];
    private const ONSITE_200_PLUS=[1990,2040,2140,2290,2390,2490,2590,4140,4190,4190,4190,6590];
    private const DBA_200_PLUS=[
        'sp_capital'=>[1995,2045,2145,2295,2395,2595,2795,3695,3945,4045,4545,5995],
        'se_capital'=>[1995,2045,2145,2295,2395,2595,2795,3445,3695,4045,4695,6145],
        'se_interior'=>[2045,2095,2195,2345,2445,2595,2795,3695,3945,4045,4695,6595],
        'other'=>[2045,2095,2195,2345,2445,2595,2795,3695,3945,4045,4695,6595],
    ];

    private static function positive(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float)$value) && (float)$value>0
            ? (float)$value : null;
    }

    private static function convert(mixed $v,string $kind): ?float
    {
        if(!is_array($v)||($num=self::positive($v['value']??null))===null)return null;
        $u=strtolower((string)($v['unit']??''));
        $factor=$kind==='weight' ? match($u){
            'kilograms','kilogram','kg'=>1.0,'grams','gram','g'=>0.001,
            'pounds','pound','lb'=>0.45359237,'ounces','ounce','oz'=>0.028349523125,
            default=>null
        }:match($u){
            'centimeters','centimeter','cm'=>1.0,'millimeters','millimeter','mm'=>0.1,
            'meters','meter','m'=>100.0,'inches','inch','in'=>2.54,default=>null
        };
        return $factor===null?null:$num*$factor;
    }

    public static function amazonPackage(array $attributes): ?array
    {
        $d=$attributes['item_package_dimensions'][0]??null;
        if(!is_array($d))return null;
        $package=[
            'weight_kg'=>self::convert($attributes['item_package_weight'][0]??null,'weight'),
            'length_cm'=>self::convert($d['length']??null,'length'),
            'width_cm'=>self::convert($d['width']??null,'length'),
            'height_cm'=>self::convert($d['height']??null,'length')
        ];
        return in_array(null,$package,true)?null:$package;
    }

    public static function erpPackage(array $dims): ?array
    {
        $package=[
            'weight_kg'=>self::positive($dims['pesoBruto']??null),
            'length_cm'=>self::positive($dims['comprimento']??null),
            'width_cm'=>self::positive($dims['largura']??null),
            'height_cm'=>self::positive($dims['altura']??null)
        ];
        return in_array(null,$package,true)?null:$package;
    }

    public static function billable(array $package): ?array
    {
        $kg=self::positive($package['weight_kg']??null);
        $l=self::positive($package['length_cm']??null);
        $w=self::positive($package['width_cm']??null);
        $h=self::positive($package['height_cm']??null);
        if($kg===null||$l===null||$w===null||$h===null)return null;
        $cubed=$l*$w*$h/6000;
        $weight=max($kg,$cubed)+0.02; // published 20g standard package allowance
        return ['actual_kg'=>$kg,'dimensional_kg'=>$cubed,'billable_kg'=>$weight,
            'eligible'=>$weight<=30&&max($l,$w,$h)<=100&&$l+$w+$h<200];
    }

    private static function fromTable(array $values,int $additional,float $kg): int
    {
        foreach(self::KG as $i=>$limit){
            if($kg<=$limit+0.00000001)return $values[$i];
        }
        return $values[11]+$additional*(int)ceil($kg-10-0.00000001);
    }

    public static function logisticsCents(string $program,int $price,?float $kg,?string $region): ?int
    {
        if(!in_array($program,['FBA_ONSITE','DBA'],true)||$price<=0)return null;
        if($price<3000)return $program==='DBA'?450:625;
        if($price<5000)return 650;
        if($price<7900)return 675;
        if($kg===null||$kg<=0||$kg>30)return null;
        if($price<20000){
            $idx=$price<10000?0:($price<12000?1:($price<15000?2:3));
            return self::fromTable(self::BETWEEN_79_200[$idx],$idx===3?350:305,$kg);
        }
        if($program==='FBA_ONSITE')return self::fromTable(self::ONSITE_200_PLUS,400,$kg);
        if($region===null||!isset(self::DBA_200_PLUS[$region]))return null;
        return self::fromTable(self::DBA_200_PLUS[$region],400,$kg);
    }

    /** @return array<string,mixed> */
    public static function calculate(array $row,?array $quote,int $taxBps=1400,?string $region=null): array
    {
        $out=[
            'current_margin_status'=>'pending','current_margin_bps'=>null,
            'current_profit_cents'=>null,'current_tax_cents'=>null,
            'current_commission_cents'=>null,'current_logistics_cents'=>null,
            'current_wms_cents'=>null,'current_weight_kg'=>null,
            'current_dimensional_kg'=>null,'current_billable_kg'=>null,
            'current_package_source'=>null,'current_package_conflict'=>false,
            'current_tariff_source'=>'AMAZON_OFFICIAL_BR_FBA_ONSITE_DBA_2025_08',
            'current_fee_source'=>null,
        ];
        if(!in_array('BUYABLE',is_array($row['amazon_status']??null)?$row['amazon_status']:[],true)){
            $out['current_margin_status']='not_buyable';return $out;
        }
        $price=self::positive($row['price']??null);
        $cost=self::positive($row['last_cost']??null);
        $program=(string)($row['program']??'');
        if(!in_array($program,['FBA_ONSITE','DBA'],true)){
            $out['current_margin_status']='program_unverified';return $out;
        }
        if($price===null){$out['current_margin_status']='price_missing';return $out;}
        if($cost===null){$out['current_margin_status']='cost_or_kit_unverified';return $out;}
        $p=(int)round($price*100);
        if(!is_array($quote)||!isset($quote['commission_cents'],$quote['price_cents'])){
            $out['current_margin_status']='fee_quote_missing';return $out;
        }
        if((int)$quote['price_cents']!==$p){
            $out['current_margin_status']='fee_quote_price_mismatch';return $out;
        }
        $out['current_commission_cents']=(int)$quote['commission_cents'];
        $out['current_fee_source']='AMAZON_SP_API_PRODUCT_FEES_CURRENT_PRICE';
        $amazon=$row['amazon_package']??null;$erp=$row['erp_package']??null;
        $pkg=null;
        if(is_array($amazon)){$pkg=$amazon;$out['current_package_source']='AMAZON_CATALOG_PACKAGE';}
        elseif(is_array($erp)&&(int)($row['kit_cost_multiplier']??1)===1){
            $pkg=$erp;$out['current_package_source']='OLIST_V3_REGISTERED_PACKAGE';
        }
        $weight=is_array($pkg)?self::billable($pkg):null;
        if($weight!==null){
            $out['current_weight_kg']=round($weight['actual_kg'],4);
            $out['current_dimensional_kg']=round($weight['dimensional_kg'],4);
            $out['current_billable_kg']=round($weight['billable_kg'],4);
        }
        if(is_array($amazon)&&is_array($erp)){
            $aa=self::billable($amazon);$bb=self::billable($erp);
            if($aa!==null&&$bb!==null){
                $out['current_package_conflict']=abs($aa['billable_kg']-$bb['billable_kg'])
                    >max(0.2,0.05*$aa['billable_kg']);
            }
        }
        if($weight!==null&&!$weight['eligible']){
            $out['current_margin_status']='package_outside_program_limits';return $out;
        }
        $freight=self::logisticsCents($program,$p,$weight['billable_kg']??null,$region);
        if($freight===null){
            $out['current_margin_status']=$weight===null?'package_dimensions_missing':'origin_region_unverified';
            return $out;
        }
        if($taxBps<0||$taxBps>10000){$out['current_margin_status']='invalid_tax_rate';return $out;}
        $tax=(int)round($p*$taxBps/10000,0,PHP_ROUND_HALF_UP);
        $profit=$p-(int)round($cost*100)-(int)$quote['commission_cents']-$tax-$freight;
        $out['current_tax_cents']=$tax;
        $out['current_logistics_cents']=$freight;
        $out['current_wms_cents']=0;
        $out['current_profit_cents']=$profit;
        $out['current_margin_bps']=(int)round($profit*10000/$p,0,PHP_ROUND_HALF_UP);
        $out['current_margin_status']=$out['current_package_conflict']
            ?'estimated_dimension_conflict':'estimated';
        return $out;
    }
}
