<?php
declare(strict_types=1);
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
echo "PASS current fee quotes: referral-only Onsite, no historic price, unknown fees rejected\n";
