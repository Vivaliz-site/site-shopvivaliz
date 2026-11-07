<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/ReportExport.php';

$rows = [
 ['seller_sku'=>'armastrafba2','erp_sku'=>'A21','asin'=>'B08T6GQM2N','program'=>'FBA_ONSITE',
  'price'=>78.99,'realized_unit_price'=>75.0,'realized_sale_gross_cents'=>67500,
  'last_cost'=>37.27,'confirmed_units'=>9,'pending_sales'=>0,'margin_weighted_bps'=>1278,
  'margin_min_bps'=>1278,'profit_cents'=>9081,'last_cost_date'=>'2026-09-17','status'=>'confirmed',
  'current_margin_bps'=>850,'current_profit_cents'=>6714,'current_commission_cents'=>8689,
  'current_logistics_cents'=>675,'current_tax_cents'=>11059,'current_weight_kg'=>0.85,
  'current_dimensional_kg'=>1.2,'current_billable_kg'=>1.22,
  'current_margin_status'=>'estimated','current_package_source'=>'AMAZON_CATALOG_PACKAGE',
  'historical_fulfillment_source'=>'AMAZON_ORDERS_V2026_FULFILLMENT'],
 ['seller_sku'=>'1C7Q-LKVS-KOPL','erp_sku'=>'TAJ/AS*BR1-NOVO','asin'=>'B0BM514NX7','program'=>'DBA',
  'price'=>71.91,'last_cost'=>42.90,'confirmed_units'=>12,'pending_sales'=>1,'margin_weighted_bps'=>445,
  'margin_min_bps'=>445,'profit_cents'=>3840,'last_cost_date'=>'2026-09-07','status'=>'partial',
  'current_margin_bps'=>783,'current_margin_status'=>'estimated_program_unverified'],
];
$data = SvAmazonMarginExport::rows(['rows'=>$rows],[]);
if (count($data)!==2) throw new Exception('all rows');
if (abs(($data[0]['Margem atual estimada (%)']??-1)-8.50)>0.0001
    || ($data[0]['Comissao Amazon atual (R$)']??null)!==86.89
    || ($data[0]['Logistica atual (R$)']??null)!==6.75
    || ($data[0]['Peso tarifavel (kg)']??null)!==1.22
    || ($data[0]['Situacao margem atual']??'')!=='estimated')
    throw new Exception('current price margin breakdown must be exported');

if ($data[0]['Fonte canal historico']!=='AMAZON_ORDERS_V2026_FULFILLMENT')
    throw new Exception('historical source must be attributed rather than inferred');

if ($data[0]['Receita bruta media por unidade (R$)']!==75.0
    || $data[0]['Receita bruta das unidades conciliadas (R$)']!==675.0) {
    throw new Exception('report must preserve actual financial gross, distinct from current offer');
}
$one=SvAmazonMarginExport::rows(['rows'=>$rows],['1C7Q-LKVS-KOPL']);
if (count($one)!==1 || $one[0]['SKU Amazon']!=='1C7Q-LKVS-KOPL') throw new Exception('selected rows');
$x=SvAmazonMarginExport::xlsx($data);
$tmp=tempnam(sys_get_temp_dir(),'margin-test-');
file_put_contents($tmp,$x);
$zip=new ZipArchive();
if ($zip->open($tmp)!==true) throw new Exception('xlsx invalid');
$sheet=$zip->getFromName('xl/worksheets/sheet1.xml');
if (!is_string($sheet) || !str_contains($sheet,'armastrafba2') || !str_contains($sheet,'1C7Q-LKVS-KOPL')) throw new Exception('xlsx items');
$xml=simplexml_load_string($sheet);
if (!$xml) throw new Exception('xlsx xml');
if (!str_contains($sheet,'12.78')) throw new Exception('margin output');
if (!str_contains($sheet,'Margem atual estimada (%)') || !str_contains($sheet,'Comissao Amazon atual (R$)')) throw new Exception('new XLSX current margin columns missing');
if(!str_contains($sheet,'Fonte canal historico')
    || !str_contains($sheet,'AMAZON_ORDERS_V2026_FULFILLMENT')) {
    throw new Exception('historical order provenance missing from XLSX');
}
if (!str_contains($sheet,'Receita bruta media por unidade (R$)')
    || !str_contains($sheet,'Receita bruta das unidades conciliadas (R$)'))
    throw new Exception('realized gross columns missing');
$zip->close();unlink($tmp);
$p=SvAmazonMarginExport::pdf($data,'2026-11-03');
if (!str_starts_with($p,'%PDF-1.4') || !str_contains($p,'%%EOF') || !str_contains($p,'1 0 obj')) throw new Exception('pdf invalid');
if (strlen($p)<1500) throw new Exception('pdf too small');
if (!str_contains($p,'Receita bruta media/unidade: R$ 75'))
    throw new Exception('pdf must distinguish actual price from current offer price');
if (!str_contains($p,'(Marg. atual) Tj ET')) throw new Exception('PDF must mark projected current margin');
if (!str_contains($p,'RESSALVA: DBA presumido')) throw new Exception('PDF must show unverified DBA projection caveat');
if (!str_contains($p,'(Unid. conf.) Tj ET') || str_contains($p,'(Vendas) Tj ET')) throw new Exception('pdf header must label confirmed units, not sales');
if (!str_contains($p,'(9) Tj ET') || !str_contains($p,'(12) Tj ET')) throw new Exception('pdf confirmed unit quantities missing');
if (str_contains($sheet,'Preco medio efetivo das vendas')
    || str_contains($p,'Preco medio efetivo nas vendas')) {
    throw new Exception('Gross including shipping cannot be presented as product price');
}
// Regression from persisted production run #14: three DBA Shipment rows had
// pending_sales=1, confirmed_sales=0 but were labelled "no sales" in UI/exports.
// Relabel for presentation without mutating the historical audit payload.
$legacy=SvAmazonMarginExport::rows(['rows'=>[
    ['seller_sku'=>'80cm','amazon_status'=>['BUYABLE'],'status'=>'no_confirmed_sales',
     'confirmed_sales'=>0,'confirmed_units'=>0,'pending_sales'=>1,
     'margin_weighted_bps'=>null,'profit_cents'=>0,'program'=>'DBA'],
]],[]);
if (count($legacy)!==1
    || $legacy[0]['Situacao']!=='pending_financial_reconciliation'
    || !str_contains($legacy[0]['Diagnostico'],'Venda identificada')
    || $legacy[0]['Vendas pendentes']!==1
    || $legacy[0]['Margem ponderada (%)']!==null
    || $legacy[0]['Lucro acumulado do periodo (R$)']!==null) {
    throw new Exception('pending financial shipment must never look like zero sales or certified margin');
}
$legacyXlsx=SvAmazonMarginExport::xlsx($legacy);
$legacyFile=tempnam(sys_get_temp_dir(),'sv-margin-pending-');
file_put_contents($legacyFile,$legacyXlsx);
$legacyZip=new ZipArchive();
if($legacyZip->open($legacyFile)!==true) throw new Exception('legacy pending XLSX invalid');
$legacySheet=$legacyZip->getFromName('xl/worksheets/sheet1.xml');
if(!str_contains((string)$legacySheet,'pending_financial_reconciliation')
    || !str_contains((string)$legacySheet,'Venda identificada')) {
    throw new Exception('XLSX must disclose unresolved financial shipment');
}
$legacyZip->close();unlink($legacyFile);
$legacyPdf=SvAmazonMarginExport::pdf($legacy,'2026-11-07');
if(!str_starts_with($legacyPdf,'%PDF-1.4')) throw new Exception('pending PDF invalid');

echo "PASS margin exports: XLSX file/worksheet/data + PDF header/footer + selection\n";
