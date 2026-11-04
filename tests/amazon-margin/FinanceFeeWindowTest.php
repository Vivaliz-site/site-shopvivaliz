<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/OlistAmazonOrderLink.php';
$fee=function(string $date,string $type='ServiceFee',string $description='MfnPostageFee'):array{
 return [
  'transactionType'=>$type,'description'=>$description,'postedDate'=>$date,
  'totalAmount'=>['currencyAmount'=>-6.70]
 ];
};
$period='2026-10-01T00:00:00Z';
$history=[
 $fee('2026-09-20T14:00:00Z'),
 $fee('2026-10-01T00:00:00Z'),
 $fee('2026-10-18T16:00:00.135Z'),
 $fee('2026-10-19T10:00:00Z','Shipment'),
 $fee('2026-10-21T17:30:00Z','ServiceFee','OtherFee'),
];
$qualifying=array_values(array_filter($history,
 static fn($t)=>SvAmazonMarginOlistOrderLink::postageInFinancialWindow($t,$period)));
if(count($qualifying)!==2)
 throw new RuntimeException('DBA older postage wrongly included in current-period margin');
if(!SvAmazonMarginOlistOrderLink::inFinancialWindow('2026-09-20T14:00:00Z','2026-09-01T00:00:00Z'))
 throw new RuntimeException('historical order should remain available for ERP mapping');
if(SvAmazonMarginOlistOrderLink::postageInFinancialWindow($fee(''),$period))
 throw new RuntimeException('missing postedDate must not qualify as a shipping debit');
echo "PASS finance-window: old orders available for ERP identity; only report-period MfnPostageFee qualifies\n";
