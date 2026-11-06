<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/amazon-margin/ReportExport.php';
$rows=[
 ['seller_sku'=>'A21','asin'=>'B08T6GQM2N','amazon_status'=>['BUYABLE','DISCOVERABLE'],
  'erp_sku'=>'A21','ean'=>'7890000000001','erp_match_method'=>'EAN',
  'status'=>'confirmed','price'=>78.99,'listed_price'=>78.99,'last_cost'=>37.27,
  'margin_weighted_bps'=>1378,'margin_min_bps'=>1378],
 ['seller_sku'=>'INACTIVE','asin'=>'B076YK8VKR','amazon_status'=>['DISCOVERABLE'],
  'status'=>'unmapped_erp','price'=>null,'listed_price'=>175.99,'last_cost'=>null,
  'erp_mapping_reason'=>'amazon_gtin_absent_no_direct_sku'],
 ['seller_sku'=>'ACTIVE-NO-SALE','amazon_status'=>['BUYABLE'],
  'status'=>'no_confirmed_sales','price'=>82.00,'listed_price'=>82.00,
  'last_cost'=>40.00,'margin_weighted_bps'=>null]
];
$active=array_values(array_filter($rows,static fn($r)=>in_array('BUYABLE',$r['amazon_status'],true)));
if(count($active)!==2)throw new RuntimeException('active filter source invalid');
$selected=array_column($active,'seller_sku');
$all=SvAmazonMarginExport::rows(['rows'=>$rows]);
$only=SvAmazonMarginExport::rows(['rows'=>$rows],$selected);
if(count($all)!==3||count($only)!==2)throw new RuntimeException('active export scope broken');
if($all[1]['Ativo Amazon']!=='Nao'||$all[1]['Preco cadastro Amazon (R$)']!==175.99
   ||$all[1]['Preco Amazon (R$)']!==null||$all[1]['Diagnostico']==='')
   throw new RuntimeException('inactive offer must have explicit reference price and diagnostic');
if($only[1]['Margem ponderada (%)']!==null||$only[1]['Diagnostico']!=='Sem vendas conciliadas no periodo')
   throw new RuntimeException('no sale must not report fake margin');
if($only[0]['Fonte do vinculo']!=='EAN')throw new RuntimeException('ERP mapping provenance missing');
$xlsx=SvAmazonMarginExport::xlsx($only);
$tmp=tempnam(sys_get_temp_dir(),'sv-active-');
file_put_contents($tmp,$xlsx);
$z=new ZipArchive();
if($z->open($tmp)!==true)throw new RuntimeException('invalid xlsx');
$xml=$z->getFromName('xl/worksheets/sheet1.xml');
$z->close();unlink($tmp);
if(!is_string($xml)||!str_contains($xml,'Ativo Amazon')||!str_contains($xml,'ACTIVE-NO-SALE')
  ||str_contains($xml,'INACTIVE'))throw new RuntimeException('active rows not exported correctly');
$pdf=SvAmazonMarginExport::pdf($only,'2026-11-04');
if(!str_contains($pdf,'Ativos: 2'))throw new RuntimeException('PDF active count missing');
$adminUi=file_get_contents(dirname(__DIR__,2).'/admin/amazon-margin.php');
if(!is_string($adminUi)
  ||!str_contains($adminUi,'<strong>Amazon Classic</strong> não disponibiliza exportação de anúncios')
  ||!str_contains($adminUi,'Produtos → editar → Anúncios')) {
    throw new RuntimeException('Amazon Classic UI incorrectly requests unsupported ad export');
}
echo "PASS active-only Excel/PDF scope, ERP diagnostics, null-margin safety and Classic guidance\n";
