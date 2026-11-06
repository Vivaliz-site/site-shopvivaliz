<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/marketplace/MarketplaceRuntime.php';
require_once __DIR__.'/../../includes/amazon-margin/CurrentFeeEstimates.php';
$expected=['sku'=>'C08BR','price_cents'=>154900];
$row=[
 'Status'=>'Success',
 'FeesEstimateIdentifier'=>['IdValue'=>'C08BR','MarketplaceId'=>'A2Q3Y263D00KWC',
 'PriceToEstimateFees'=>['ListingPrice'=>['CurrencyCode'=>'BRL','Amount'=>1549]]],
 'FeesEstimate'=>['FeeDetailList'=>[
 ['FeeType'=>'ReferralFee','FinalFee'=>['CurrencyCode'=>'BRL','Amount'=>170.39]],
 ['FeeType'=>'FBAFees','FinalFee'=>['CurrencyCode'=>'BRL','Amount'=>6]],
 ]],
];
$out=SvAmazonCurrentFeeEstimates::normalize($row,$expected,'A2Q3Y263D00KWC');
if(($out['commission_cents']??null)!==17039)throw new RuntimeException('classic FBAFees incorrectly counted');
$row['FeesEstimateIdentifier']['PriceToEstimateFees']['ListingPrice']['Amount']=1417.79;
if(SvAmazonCurrentFeeEstimates::normalize($row,$expected,'A2Q3Y263D00KWC')!==null)
 throw new RuntimeException('historical price quote must fail closed');
$row['FeesEstimateIdentifier']['PriceToEstimateFees']['ListingPrice']['Amount']=1549;
$row['FeesEstimate']['FeeDetailList'][]=['FeeType'=>'UnknownFutureFee','FinalFee'=>['CurrencyCode'=>'BRL','Amount'=>4]];
if(SvAmazonCurrentFeeEstimates::normalize($row,$expected,'A2Q3Y263D00KWC')!==null)
 throw new RuntimeException('new provider fee types require reconciliation');
$attempts=0;$waits=[];
$probe=SvAmazonCurrentFeeEstimates::retryQuoteOnQuota(
 static function()use(&$attempts):array{
  if(++$attempts<3)throw new SvMarketplaceException('quota',429);
  return ['status'=>200,'data'=>[]];
 },static function(int $us)use(&$waits):void{$waits[]=$us;});
if($attempts!==3||$waits!==[2500000,6000000]||($probe['status']??0)!==200)
 throw new RuntimeException('429 retry must be bounded and idempotent');
$attempts=0;$waits=[];
try{
 SvAmazonCurrentFeeEstimates::retryQuoteOnQuota(
  static function()use(&$attempts):array{$attempts++;throw new SvMarketplaceException('quota',429);},
  static function(int $us)use(&$waits):void{$waits[]=$us;});
 throw new RuntimeException('exhausted quota was not rejected');
}catch(SvMarketplaceException $e){
 if($e->httpStatus!==429||$attempts!==4||$waits!==[2500000,6000000,15000000])
  throw new RuntimeException('quota retry exhaustion contract invalid');
}
$attempts=0;
try{
 SvAmazonCurrentFeeEstimates::retryQuoteOnQuota(
  static function()use(&$attempts):array{$attempts++;throw new SvMarketplaceException('forbidden',403);},
  static function():void{throw new RuntimeException('no retry for 403');});
 throw new RuntimeException('nonquota error was suppressed');
}catch(SvMarketplaceException $e){if($e->httpStatus!==403||$attempts!==1)throw $e;}
echo "PASS current fee quotes: referral-only Onsite, price validation, 429 bounded retries, no retry 403\n";
