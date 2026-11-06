<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/ReportExport.php';
$rows=[[
 'seller_sku'=>'1C7Q-LKUB-1A9M','asin'=>'B076Y8BT55',
 'amazon_status'=>['BUYABLE'],'ean'=>'7894162000168',
 'price'=>142.90,'last_cost'=>null,'status'=>'erp_or_catalog_read_failed',
 'margin_weighted_bps'=>null,'margin_min_bps'=>null,
 'prior_cost_reference'=>[
     'cost'=>83.00,'cost_date'=>'2026-09-17','erp_id'=>341682112,
     'erp_sku'=>'6','verified_run_id'=>3,'verified_at'=>'2026-11-04 00:23:26',
     'source'=>'OLIST_TINY_V3_PRODUCT_COST_HISTORY'
 ]
]];
$data=SvAmazonMarginExport::rows(['rows'=>$rows]);
$r=$data[0];
if ($r['Custo ERP por conjunto vendido (R$)']!==null || $r['Custo anterior verificado (R$)']!==83.0
    || $r['Auditoria custo anterior']!==3 || $r['Margem ponderada (%)']!==null) {
    throw new RuntimeException('historical cost incorrectly treated as current or margin');
}
$xlsx=SvAmazonMarginExport::xlsx($data);
$tmp=tempnam(sys_get_temp_dir(),'sv-margin-prior-');
try {
    file_put_contents($tmp,$xlsx);
    $zip=new ZipArchive();
    if($zip->open($tmp)!==true)throw new RuntimeException('invalid XLSX');
    $sheet=$zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!str_contains($sheet,'Custo anterior verificado (R$)')
        || !str_contains($sheet,'Auditoria custo anterior')
        || !str_contains($sheet,'1C7Q-LKUB-1A9M')) {
        throw new RuntimeException('XLSX historical cost columns missing');
    }
} finally { if(is_file($tmp))unlink($tmp); }
$pdf=SvAmazonMarginExport::pdf($data,'2026-11-04');
if(!str_contains($pdf,'83^')||!str_contains($pdf,'Custo historico')) {
    throw new RuntimeException('PDF must annotate historical cost');
}
echo "PASS XLSX/PDF prior cost: safe historical provenance, no fabricated margin\n";
