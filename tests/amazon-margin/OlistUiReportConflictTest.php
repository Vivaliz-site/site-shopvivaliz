<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/ReportExport.php';
function verifyUiReport(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('Olist UI report contract: '.$why);
}
$conflict=[
    'seller_sku'=>'CONFLICT-001',
    'amazon_status'=>['BUYABLE'],
    'erp_sku'=>null,
    'last_cost'=>null,
    'price'=>89.99,
    'margin_weighted_bps'=>null,
    'status'=>'ambiguous_erp',
    'erp_ui_conflict'=>'current_or_historical_ui_disagrees_with_EAN',
    'erp_ui_candidate_sku'=>'ERP-NEW',
    'erp_ui_observed_at'=>'2026-11-05T22:00:00Z'
];
$confirmed=[
    'seller_sku'=>'CONFIRMED-002',
    'amazon_status'=>['BUYABLE'],
    'erp_sku'=>'ERP-EXACT',
    'last_cost'=>12.34,
    'price'=>34.45,
    'margin_weighted_bps'=>1500,
    'status'=>'confirmed',
    'erp_match_method'=>'OLIST_AUTHENTICATED_UI',
    'erp_ui_observed_at'=>'2026-11-05T22:00:00Z'
];
$rows=SvAmazonMarginExport::rows(['rows'=>[$conflict,$confirmed]]);
verifyUiReport(count($rows)===2,'both listings preserved');
verifyUiReport($rows[0]['Divergencia UI Olist']==='Sim','conflict label missing');
verifyUiReport($rows[0]['Custo ERP (R$)']===null,'conflicted cost must be null');
verifyUiReport($rows[0]['Margem ponderada (%)']===null,'conflicted margin must be null');
verifyUiReport(str_contains($rows[0]['Diagnostico'],'custo/margem bloqueados'),'conflict diagnosis absent');
verifyUiReport($rows[0]['Produto sugerido pela UI Olist']==='ERP-NEW','audit trace candidate missing');
verifyUiReport($rows[1]['Divergencia UI Olist']==='Nao','verified identity must not be marked conflicting');
verifyUiReport($rows[1]['Fonte do vinculo']==='OLIST_AUTHENTICATED_UI','source should not masquerade as XLS');
verifyUiReport($rows[1]['Custo ERP (R$)']===12.34,'certified cost should remain');
verifyUiReport($rows[1]['Margem ponderada (%)']===15.0,'certified margin should remain');
$xlsx=SvAmazonMarginExport::xlsx($rows);
$tmp=tempnam(sys_get_temp_dir(),'sv-ui-report-');
file_put_contents($tmp,$xlsx);
try {
    $zip=new ZipArchive();
    verifyUiReport($zip->open($tmp)===true,'xlsx archive invalid');
    $sheet=(string)$zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    verifyUiReport(str_contains($sheet,'CONFLICT-001') && str_contains($sheet,'ERP-NEW'),
        'xlsx lost traceability');
    verifyUiReport(str_contains($sheet,'Divergencia UI Olist'), 'xlsx did not label UI conflict');
} finally { @unlink($tmp); }
$pdf=SvAmazonMarginExport::pdf($rows,'2026-11-05');
verifyUiReport(str_starts_with($pdf,'%PDF-'),'PDF missing');
verifyUiReport(str_contains($pdf,'DIVERGENCIA UI OLIST'),'PDF must visibly warn about unc ertified cost');
echo "PASS Olist UI financial report: conflicts suppress cost and margin; PDF/XLSX retain traceability\n";
