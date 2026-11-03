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
echo "PASS amazon margin accounting: 5 confirmed cases + missing fee/zero cost/tax/rounding\n";
