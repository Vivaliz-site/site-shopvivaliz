<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/ReportExport.php';
function kitReportAssert(bool $ok,string $msg):void {
    if(!$ok)throw new RuntimeException('kit report invariant: '.$msg);
}
$case=[
    'seller_sku'=>'KIT5ROD35CFREIOFBA','amazon_status'=>['BUYABLE'],
    'erp_sku'=>'1C7Q-LKUU-0BA3','asin'=>'B07FQM6FBH',
    'program'=>'FBA_ONSITE','erp_product_type'=>'S',
    'erp_description'=>'Rodízio Transparente 35MM Com Freio Chapa Giratória Níquel',
    'title'=>'Kit com 5 Rodizios 35mm com trava',
    'price'=>49.0,'last_cost'=>15.05,'erp_base_cost'=>3.01,
    'kit_units_per_listing'=>5,'kit_cost_multiplier'=>5,
    'kit_cost_basis'=>'OLIST_COMPONENT_COST_X_AMAZON_PACK_UNITS',
    'confirmed_sales'=>2,'confirmed_units'=>3,'pending_sales'=>0,
    'profit_cents'=>3898,'margin_weighted_bps'=>2652,
    'margin_min_bps'=>2652,'status'=>'confirmed',
];
$rows=SvAmazonMarginExport::rows(['rows'=>[$case]]);
$r=$rows[0];
kitReportAssert($r['Custo ERP por conjunto vendido (R$)']===15.05,'complete kit cost');
kitReportAssert($r['Custo base da peca no ERP (R$)']===3.01,'base component cost');
kitReportAssert($r['Pecas por unidade vendida']===5,'5 physical components per sale');
kitReportAssert($r['Multiplicador de custo']===5,'multiply base cost once');
kitReportAssert($r['Unidades confirmadas']===3,'units sold differ from three shipments');
kitReportAssert($r['Lucro por unidade vendida (R$)']===12.99,'per kit profit R$12.99, rounded');
kitReportAssert($r['Lucro acumulado do periodo (R$)']===38.98,'period profit, not sale price');
kitReportAssert($r['Margem ponderada (%)']===26.52,'corrected margin');
$noMargin=$case;
$noMargin['seller_sku']='NOT_VERIFIED';
$noMargin['profit_cents']=0;
$noMargin['confirmed_units']=0;
$noMargin['margin_weighted_bps']=null;
$noMargin['status']='kit_cost_unverified';
$noMargin['last_cost']=null;
$blocked=SvAmazonMarginExport::rows(['rows'=>[$noMargin]])[0];
kitReportAssert($blocked['Lucro por unidade vendida (R$)']===null
    && $blocked['Lucro acumulado do periodo (R$)']===null
    && $blocked['Custo ERP por conjunto vendido (R$)']===null,
    'unknown pack composition cannot output false zero profit/cost');
$xlsx=SvAmazonMarginExport::xlsx($rows);
$tmp=tempnam(sys_get_temp_dir(),'kit-xlsx-');
file_put_contents($tmp,$xlsx);
try {
    $zip=new ZipArchive();
    kitReportAssert($zip->open($tmp)===true,'valid XLSX');
    $sheet=(string)$zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    foreach(['Lucro por unidade vendida','Lucro acumulado do periodo','Pecas por unidade vendida',
       'Custo base da peca'] as $name){
        kitReportAssert(str_contains($sheet,$name),'missing workbook distinction '.$name);
    }
} finally { @unlink($tmp); }
$pdf=SvAmazonMarginExport::pdf($rows,'2026-11-06');
kitReportAssert(str_starts_with($pdf,'%PDF-'),'valid PDF');
kitReportAssert(str_contains($pdf,'kit completo'),'PDF must label bundle cost');
echo "PASS kit costs and per-sale vs accumulated profit in XLSX/PDF; no unverifiable profit\n";
