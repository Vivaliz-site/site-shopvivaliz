<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/OlistChannelProgram.php';
require_once __DIR__.'/../../includes/amazon-margin/CurrentPriceMargin.php';

function verifyChannel(mixed $actual,mixed $expected,string $label):void {
    if($actual!==$expected)throw new RuntimeException($label.' actual='.var_export($actual,true));
}
verifyChannel(SvAmazonMarginOlistChannelProgram::resolve('Amazon','MERCHANT'),'DBA',
    'current Olist Amazon integration plus merchant fulfillment = DBA');
verifyChannel(SvAmazonMarginOlistChannelProgram::resolve('Amazon Onsite','AMAZON'),'FBA_ONSITE',
    'current Olist Amazon Onsite integration plus Amazon fulfillment = FBA Onsite');
verifyChannel(SvAmazonMarginOlistChannelProgram::resolve('Amazon','AMAZON'),null,
    'conflicting Olist and Amazon evidence cannot override Amazon fulfillment');
verifyChannel(SvAmazonMarginOlistChannelProgram::resolve('Amazon Onsite','MERCHANT'),null,
    'Onsite listing incorrectly reported as merchant cannot be classified by this source');
verifyChannel(SvAmazonMarginOlistChannelProgram::resolve('Amazon Classic','AMAZON'),null,
    'Amazon Classic integration is not FBA Onsite evidence');
verifyChannel(SvAmazonMarginOlistChannelProgram::resolve('', 'MERCHANT'),null,
    'missing Olist integration is not DBA evidence');

// Synthetic accounting figures: this proves the official DBA <= R$30 table
// does not require an origin region; it never substitutes ERP costs.
$row=['amazon_status'=>['BUYABLE'],'program'=>'DBA','price'=>27.99,
    'last_cost'=>10.50,'amazon_package'=>null,'erp_package'=>null,
    'kit_cost_multiplier'=>1];
$quote=['price_cents'=>2799,'commission_cents'=>300];
$calc=SvAmazonCurrentPriceMargin::calculate($row,$quote,1400,null);
verifyChannel($calc['current_logistics_cents'],450,'DBA freight for R$27.99');
verifyChannel($calc['current_tax_cents'],392,'14 percent tax');
verifyChannel($calc['current_profit_cents'],607,'DBA margin arithmetic in cents');
verifyChannel($calc['current_margin_status'],'estimated','verified program should not be called candidate');
$row['program']='DBA_CANDIDATE';
$old=SvAmazonCurrentPriceMargin::calculate($row,$quote,1400,null);
verifyChannel($old['current_margin_status'],'estimated_program_unverified',
    'existing assumed fulfillment state stays unchanged without verified integration');
echo "PASS Olist Amazon integration -> DBA and Onsite; Amazon corroboration; R$27.99 DBA logistics and margin\n";
