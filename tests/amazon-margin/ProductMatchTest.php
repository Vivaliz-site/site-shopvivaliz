<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/amazon-margin/ProductMatch.php';
function must(bool $ok,string $message):void {
 if(!$ok)throw new RuntimeException('ProductMatch: '.$message);
}
$a=['id'=>10,'sku'=>'A21','gtin'=>'7890000000001'];
$b=['id'=>11,'sku'=>'OTHER','gtin'=>'7890000000001'];
$sku=['a21'=>[$a], 'other'=>[$b]];
$ean=['7890000000001'=>[$a]];
$r=SvAmazonMarginProductMatch::resolve('XYZ','7890000000001',$ean,$sku);
must($r['status']==='mapped'&&$r['id']===10&&$r['method']==='EAN','unique EAN');
$r=SvAmazonMarginProductMatch::resolve('A21',null,[],$sku);
must($r['status']==='mapped'&&$r['id']===10&&$r['method']==='SELLER_SKU_EXACT','missing Amazon EAN exact SKU');
$r=SvAmazonMarginProductMatch::resolve('A21','7890000000001',['7890000000001'=>[$a,$b]],$sku);
must($r['status']==='mapped'&&$r['id']===10&&$r['method']==='SELLER_SKU_AND_EAN','duplicate EAN disambiguated by exact SKU');
$r=SvAmazonMarginProductMatch::resolve('A21','7899999999999',[],$sku);
must($r['status']==='erp_identity_mismatch'&&$r['id']===null,'conflicting GTIN rejected');
$r=SvAmazonMarginProductMatch::resolve('UNKNOWN','7890000000001',['7890000000001'=>[$a,$b]],$sku);
must($r['status']==='ambiguous_erp','duplicate GTIN without SKU rejected');
$r=SvAmazonMarginProductMatch::resolve('A21','7890000000001',['7890000000001'=>[$b]],$sku);
must($r['status']==='ambiguous_erp','conflict of SKU with EAN product rejected');
$r=SvAmazonMarginProductMatch::resolve('UNKNOWN',null,[],$sku);
must($r['status']==='unmapped_erp'&&$r['reason']==='amazon_gtin_absent_no_direct_sku','unmatched remains explicit');
$r=SvAmazonMarginProductMatch::resolve('000123',null,[],['000123'=>[['id'=>92,'sku'=>'000123','gtin'=>'']]]);
must($r['status']==='mapped'&&$r['id']===92,'SKU leading zeros preserved');
echo "PASS active ERP product resolver: EAN, exact SKU, duplicate/mismatch/unknown cases\n";
