<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/amazon-margin/MarginMath.php';

function margin_assert(bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException($why);
}

$cases = [
    ['A21 FBA', ['sku'=>'armastrafba2','price'=>78.99,'cost'=>37.27,'quantity'=>1,'shipment_total'=>58.42,'program'=>'FBA_ONSITE'], 1009, 1277],
    ['Slim FBA', ['sku'=>'ASSLIMFBA','price'=>71.91,'cost'=>42.90,'quantity'=>1,'shipment_total'=>55.45,'program'=>'FBA_ONSITE'], 248, 345],
    ['A21 DBA shipping charged and offset in separate fee', ['sku'=>'Armastradba','price'=>78.99,'cost'=>37.27,'quantity'=>1,'shipment_total'=>89.19,'program'=>'DBA','postage_total'=>-30.34,'postage_confirmed'=>true], 1052, 1332],
    ['Slim DBA', ['sku'=>'1C7Q-LKVS-KOPL','price'=>71.91,'cost'=>42.90,'quantity'=>1,'shipment_total'=>62.92,'program'=>'DBA','postage_total'=>-6.75,'postage_confirmed'=>true], 320, 445],
    ['Slim DBA two units', ['sku'=>'1C7Q-LKVS-KOPL','price'=>71.91,'cost'=>42.90,'quantity'=>2,'shipment_total'=>125.84,'program'=>'DBA','postage_total'=>-13.50,'postage_confirmed'=>true], 641, 446],
];
foreach ($cases as [$name, $line, $profit, $bps]) {
    $got = SvAmazonMarginMath::calculate($line);
    margin_assert($got['status'] === 'confirmed', "$name not confirmed");
    margin_assert($got['profit_cents'] === $profit, "$name profit " . json_encode($got));
    margin_assert(abs($got['margin_bps'] - $bps) <= 2, "$name margin " . json_encode($got));
    margin_assert($got['below_10_pct'] === ($bps < 1000), "$name threshold");
}
$noPostage = SvAmazonMarginMath::calculate(['sku'=>'dba','price'=>71.91,'cost'=>42.9,'quantity'=>1,'shipment_total'=>62.92,'program'=>'DBA']);
margin_assert($noPostage['status'] === 'pending_postage', 'Missing DBA charge must not pass');
$zeroCost = SvAmazonMarginMath::calculate(['sku'=>'zero','price'=>71.91,'cost'=>0,'quantity'=>1,'shipment_total'=>62.92,'program'=>'DBA','postage_total'=>-6.75,'postage_confirmed'=>true]);
margin_assert($zeroCost['status'] === 'unverified', 'Zero cost must not pass');
$badTax = SvAmazonMarginMath::calculate(['sku'=>'tax','price'=>71.91,'cost'=>42.9,'quantity'=>1,'shipment_total'=>62.92,'program'=>'FBA_ONSITE','tax_bps'=>10001]);
margin_assert($badTax['status'] === 'unverified', 'Invalid tax must not pass');
margin_assert(SvAmazonMarginMath::cents('0.10') === 10, 'Decimals must use cents');
margin_assert(SvAmazonMarginMath::cents('NaN') === null, 'NaN must be rejected');

// Real SP-API Shipment evidence (Brazil, 2026-11): the Amazon listing's
// CURRENT price is not the historical transaction principal. Do not apply
// tax or calculate historical margin using today's price.
$money=static fn(float $value):array=>['currencyAmount'=>$value,'currencyCode'=>'BRL'];
$fbaItem=[
    'totalAmount'=>$money(77.98),
    'breakdowns'=>[
        ['breakdownType'=>'ProductCharges','breakdownAmount'=>$money(112.97)],
        ['breakdownType'=>'AmazonFees','breakdownAmount'=>$money(-34.99)],
    ],
];
margin_assert(SvAmazonMarginMath::transactionGrossCents($fbaItem)===11297,
    'FBA sale gross must come from transaction, not live price 159');
$historical=SvAmazonMarginMath::calculate([
    'sku'=>'R9-O6PX-YBXA','price'=>159.0,'cost'=>56.29,'quantity'=>1,
    'shipment_total'=>77.98,'program'=>'FBA_ONSITE','tax_bps'=>1400,
    'sale_gross_cents'=>11297,
]);
margin_assert($historical['status']==='confirmed'
    && $historical['tax_cents']===1582 && $historical['profit_cents']===587
    && $historical['margin_bps']===520 && $historical['gross_cents']===11297,
    'Historical FBA price divergence: tax, denominator and profit must use sale');
$promo=[
    'totalAmount'=>$money(29.83),
    'breakdowns'=>[
        ['breakdownType'=>'PromoRebates','breakdownAmount'=>$money(-8.90)],
        ['breakdownType'=>'ProductCharges','breakdownAmount'=>$money(42.00)],
        ['breakdownType'=>'Shipping','breakdownAmount'=>$money(8.90)],
        ['breakdownType'=>'AmazonFees','breakdownAmount'=>$money(-12.17)],
    ],
];
margin_assert(SvAmazonMarginMath::transactionGrossCents($promo)===4200,
    'Buyer free-shipping promo must cancel shipping, not double count it');
$dba=[
    'totalAmount'=>$money(88.17),
    'breakdowns'=>[
        ['breakdownType'=>'ProductCharges','breakdownAmount'=>$money(78.99)],
        ['breakdownType'=>'Shipping','breakdownAmount'=>$money(23.59)],
        ['breakdownType'=>'AmazonFees','breakdownAmount'=>$money(-14.41)],
    ],
];
margin_assert(SvAmazonMarginMath::transactionGrossCents($dba)===10258,
    'Buyer-paid DBA shipping belongs in taxable sale gross');
$dbaReal=SvAmazonMarginMath::calculate([
    'sku'=>'Armastradba','price'=>78.99,'cost'=>37.27,'quantity'=>1,
    'shipment_total'=>88.17,'program'=>'DBA','postage_total'=>-30.34,
    'postage_confirmed'=>true,'sale_gross_cents'=>10258,
]);
margin_assert($dbaReal['tax_cents']===1436 && $dbaReal['profit_cents']===620
    && $dbaReal['margin_bps']===604,
    'DBA shipping included in tax gross, but separate postage deducted once');
$noSaleGross=SvAmazonMarginMath::calculate([
    'sku'=>'fail_closed','price'=>159,'cost'=>56.29,'quantity'=>1,
    'shipment_total'=>77.98,'program'=>'FBA_ONSITE','sale_gross_cents'=>null,
]);
margin_assert($noSaleGross['status']==='unverified',
    'Missing transaction gross cannot be certified using listing price');
$unknown=$fbaItem;
$unknown['breakdowns'][]=['breakdownType'=>'NewUnknownAmazonFee','breakdownAmount'=>$money(-1)];
margin_assert(SvAmazonMarginMath::transactionGrossCents($unknown)===null,
    'New financial breakdown types must not be guessed or double counted');
$badNet=$fbaItem;
$badNet['totalAmount']=$money(80.00);
margin_assert(SvAmazonMarginMath::transactionGrossCents($badNet)===null,
    'Breakdown and actual net mismatch must block certification');
$wrongCurrency=$fbaItem;
$wrongCurrency['breakdowns'][0]['breakdownAmount']['currencyCode']='USD';
margin_assert(SvAmazonMarginMath::transactionGrossCents($wrongCurrency)===null,
    'Financial BRL amounts cannot silently mix currencies');
echo "PASS transaction gross: actual sale price, DBA freight, rebates, net reconciliation, fail-closed\n";


// A later Seller Flex stock removal can make current Pricing return no
// effective offer. Verified historical gross is still authoritative.
$inactiveHistorical=SvAmazonMarginMath::calculate([
    'sku'=>'ABRA-PERF-FBA','price'=>null,'cost'=>25.00,'quantity'=>2,
    'shipment_total'=>55.64,'program'=>'FBA_ONSITE',
    'sale_gross_cents'=>7800,'tax_bps'=>1400,
]);
margin_assert($inactiveHistorical['status']==='confirmed'
    && $inactiveHistorical['gross_cents']===7800
    && $inactiveHistorical['tax_cents']===1092
    && $inactiveHistorical['profit_cents']===-528
    && $inactiveHistorical['margin_bps']===-677,
    'Historical actual sale must survive removal of current Amazon Pricing offer');
$inactiveWithoutGross=SvAmazonMarginMath::calculate([
    'sku'=>'NOT_CERTIFIED','price'=>null,'cost'=>25.00,'quantity'=>2,
    'shipment_total'=>55.64,'program'=>'FBA_ONSITE','sale_gross_cents'=>null,
]);
margin_assert($inactiveWithoutGross['status']==='unverified',
    'Missing current price AND transaction gross must fail closed');
$inactiveWithoutCost=SvAmazonMarginMath::calculate([
    'sku'=>'NOT_CERTIFIED','price'=>null,'cost'=>null,'quantity'=>2,
    'shipment_total'=>55.64,'program'=>'FBA_ONSITE','sale_gross_cents'=>7800,
]);
margin_assert($inactiveWithoutCost['status']==='unverified',
    'Verified gross cannot substitute unverified ERP kit/component cost');
echo "PASS inactive-current-offer transactional gross only (no guessing)\n";

echo "PASS amazon margin accounting: 5 confirmed cases + missing fee/zero cost/tax/rounding\n";
