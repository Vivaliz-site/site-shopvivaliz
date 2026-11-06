<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/ReportExport.php';

$rows = [
 ['seller_sku'=>'armastrafba2','erp_sku'=>'A21','asin'=>'B08T6GQM2N','program'=>'FBA_ONSITE',
  'price'=>78.99,'realized_unit_price'=>75.0,'realized_sale_gross_cents'=>67500,
  'last_cost'=>37.27,'confirmed_units'=>9,'pending_sales'=>0,'margin_weighted_bps'=>1278,
  'margin_min_bps'=>1278,'profit_cents'=>9081,'last_cost_date'=>'2026-09-17','status'=>'confirmed'],
 ['seller_sku'=>'1C7Q-LKVS-KOPL','erp_sku'=>'TAJ/AS*BR1-NOVO','asin'=>'B0BM514NX7','program'=>'DBA',
  'price'=>71.91,'last_cost'=>42.90,'confirmed_units'=>12,'pending_sales'=>1,'margin_weighted_bps'=>445,
  'margin_min_bps'=>445,'profit_cents'=>3840,'last_cost_date'=>'2026-09-07','status'=>'partial'],
];
$data = SvAmazonMarginExport::rows(['rows'=>$rows],[]);
if (count($data)!==2) throw new Exception('all rows');
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
if (!str_contains($sheet,'Receita bruta media por unidade (R$)')
    || !str_contains($sheet,'Receita bruta das unidades conciliadas (R$)'))
    throw new Exception('realized gross columns missing');
$zip->close();unlink($tmp);
$p=SvAmazonMarginExport::pdf($data,'2026-11-03');
if (!str_starts_with($p,'%PDF-1.4') || !str_contains($p,'%%EOF') || !str_contains($p,'1 0 obj')) throw new Exception('pdf invalid');
if (strlen($p)<1500) throw new Exception('pdf too small');
if (!str_contains($p,'Receita bruta media/unidade: R$ 75'))
    throw new Exception('pdf must distinguish actual price from current offer price');
if (!str_contains($p,'(Unid. conf.) Tj ET') || str_contains($p,'(Vendas) Tj ET')) throw new Exception('pdf header must label confirmed units, not sales');
if (!str_contains($p,'(9) Tj ET') || !str_contains($p,'(12) Tj ET')) throw new Exception('pdf confirmed unit quantities missing');
if (str_contains($sheet,'Preco medio efetivo das vendas')
    || str_contains($p,'Preco medio efetivo nas vendas')) {
    throw new Exception('Gross including shipping cannot be presented as product price');
}
echo "PASS margin exports: XLSX file/worksheet/data + PDF header/footer + selection\n";
