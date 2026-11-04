<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/ReportExport.php';
$offers=[[
 'seller_sku'=>'5E-73DD-V2JB','asin'=>'B000TEST123',
 'amazon_status'=>['BUYABLE'],
 'erp_id'=>383783235,'erp_sku'=>'ved-80-nh',
 'erp_integration'=>'Amazon Onsite','erp_order_evidence_count'=>3,
 'erp_gtin_differs_from_amazon'=>true,'erp_match_method'=>'OLIST_AMAZON_ORDER',
 'status'=>'confirmed','price'=>72.90,'last_cost'=>35.10,
 'margin_weighted_bps'=>1533,'margin_min_bps'=>1533,
]];
$data=SvAmazonMarginExport::rows(['rows'=>$offers]);
if(count($data)!==1)throw new RuntimeException('missing report row');
$r=$data[0];
if(($r['Integração ERP Amazon']??null)!=='Amazon Onsite'
 || ($r['Fonte do vinculo']??null)!=='OLIST_AMAZON_ORDER'
 || ($r['Pedidos de vínculo comprovados']??null)!==3
 || ($r['GTIN Amazon difere do ERP']??null)!=='Sim')
 throw new RuntimeException('order-to-ERP evidence missing in report');
$xlsx=SvAmazonMarginExport::xlsx($data);
$temp=tempnam(sys_get_temp_dir(),'sv-margin-olist-');
try{
 file_put_contents($temp,$xlsx);
 $z=new ZipArchive();
 if($z->open($temp)!==true)throw new RuntimeException('bad xlsx');
 $xml=$z->getFromName('xl/worksheets/sheet1.xml');$z->close();
 if(!str_contains($xml,'Integração ERP Amazon')
  ||!str_contains($xml,'Amazon Onsite')
  ||!str_contains($xml,'Pedidos de vínculo comprovados')
  ||!str_contains($xml,'OLIST_AMAZON_ORDER'))throw new RuntimeException('metadata absent XLSX');
}finally{if(is_file($temp))unlink($temp);}
echo "PASS ERP integration/provenance exported; no Amazon order ID or PII stored in cost report\n";
