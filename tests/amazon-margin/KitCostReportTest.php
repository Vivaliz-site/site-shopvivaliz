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
kitReportAssert(str_contains($pdf,'Lucro por unidade vendida: R$ 12.99'),
    'PDF must show per-unit/kit profit, not accumulated profit as one sale');
kitReportAssert(str_contains($pdf,'Lucro acumulado: R$ 38.98'),
    'PDF must show accumulated profit separately from unit price');
kitReportAssert(str_contains($pdf,'Pecas por kit: 5'),
    'PDF must disclose pieces in each sold kit');
$many=array_fill(0,40,$rows[0]);
$multiPage=SvAmazonMarginExport::pdf($many,'2026-11-06');
kitReportAssert(substr_count($multiPage,'/Type /Page /Parent')===3,
    '40 listings need exactly 3 pages at 17 rows per page');
kitReportAssert(substr_count($multiPage,'Lucro por unidade vendida: R$ 12.99')===40,
    'each PDF page/row must include the per-kit profit');
kitReportAssert(substr_count($multiPage,'Lucro acumulado: R$ 38.98')===40,
    'each PDF page/row must include period profit');
$mismatch=$case;
$mismatch['seller_sku']='KIT2C2SFBA50';
$mismatch['erp_product_type']='K';
$mismatch['status']='kit_cost_unverified';
$mismatch['last_cost']=null;
$mismatch['erp_base_cost']=33.08;
$mismatch['erp_kit_bom_cost']=14.94;
$mismatch['erp_kit_bom_total_units']=4;
$mismatch['kit_cost_issue']='kit_registered_cost_disagrees_with_component_costs';
$mismatch['profit_cents']=0;
$mismatch['margin_weighted_bps']=null;
$mismatch['prior_cost_reference']=['cost'=>33.08,'verified_run_id'=>8,
    'cost_date'=>'2026-07-31'];
$bad=SvAmazonMarginExport::rows(['rows'=>[$mismatch]])[0];
kitReportAssert($bad['Custo ERP por conjunto vendido (R$)']===null,
    'BOM mismatch must not publish historical cost as current');
kitReportAssert($bad['Custo registrado de kit no ERP (R$)']===33.08
    && $bad['Custo calculado pela composicao do kit (R$)']===14.94,
    'Excel must preserve both incongruent values');
kitReportAssert($bad['Lucro por unidade vendida (R$)']===null
    && $bad['Lucro acumulado do periodo (R$)']===null,
    'historical margin cannot be certified');
$badPdf=SvAmazonMarginExport::pdf([$bad],'2026-11-06');
kitReportAssert(!str_contains($badPdf,'33.08'),
    'PDF historical kit cost fallback must be suppressed for mismatches');
$productTab=$case;
$productTab['erp_match_method']='OLIST_AUTHENTICATED_PRODUCT_TAB_UI';
$productTab['erp_product_tab_observed_at']='2026-11-06T05:20:00.000Z';
$productTab['erp_product_tab_verified']=true;
$productTab['erp_integration']='Amazon Onsite';
$productTabReport=SvAmazonMarginExport::rows(['rows'=>[$productTab]])[0];
kitReportAssert($productTabReport['Fonte do vinculo']==='OLIST_AUTHENTICATED_PRODUCT_TAB_UI'
    && $productTabReport['Aba Anuncios do produto Olist validada em']
        ==='2026-11-06T05:20:00.000Z'
    && $productTabReport['Vinculo confirmado pela aba Anuncios Olist']==='Sim',
    'verified Olist product-tab evidence must be visible in Excel source columns');
$productTabXlsx=SvAmazonMarginExport::xlsx([$productTabReport]);
$productTabTmp=tempnam(sys_get_temp_dir(),'sv-prod-tab-xlsx-');
file_put_contents($productTabTmp,$productTabXlsx);
try {
    $zip=new ZipArchive();
    kitReportAssert($zip->open($productTabTmp)===true,'product tab XLSX ZIP');
    $sheet=(string)$zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    kitReportAssert(str_contains($sheet,'Aba Anuncios do produto Olist validada em')
        && str_contains($sheet,'OLIST_AUTHENTICATED_PRODUCT_TAB_UI'),
        'product-tab source and date are present in actual XLSX bytes');
} finally { @unlink($productTabTmp); }
$pendingTab=$productTab;
$pendingTab['erp_product_tab_crosscheck_pending']=true;
$pendingTab['erp_historical_product_tab_candidate']=['erp_sku'=>'ERP-LEGACY'];
$pendingTab['status']='ambiguous_erp';
$pendingTab['margin_weighted_bps']=null;
$pendingTab['last_cost']=null;
$blockedTab=SvAmazonMarginExport::rows(['rows'=>[$pendingTab]])[0];
kitReportAssert($blockedTab['Reconciliacao pendente na aba de produto Olist']==='Sim'
    && $blockedTab['Candidato historico aba produto Olist']==='ERP-LEGACY'
    && $blockedTab['Lucro por unidade vendida (R$)']===null,
    'product-tab conflicts preserve evidence without certifying cost or profit');
echo "PASS kit costs and per-sale vs accumulated profit in XLSX/PDF; no unverifiable profit\n";
