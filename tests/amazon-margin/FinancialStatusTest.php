<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/FinancialStatus.php';

$cases = [
    [0, 0, 'no_confirmed_sales'],
    [0, 1, 'pending_financial_reconciliation'],
    [0, 3, 'pending_financial_reconciliation'],
    [1, 0, 'confirmed'],
    [2, 1, 'partial'],
];
foreach ($cases as [$confirmed, $pending, $expected]) {
    if (SvAmazonMarginFinancialStatus::classify($confirmed, $pending) !== $expected) {
        throw new RuntimeException('financial_status_wrong: '.json_encode([$confirmed,$pending]));
    }
}
foreach ([
    [['status'=>'no_confirmed_sales','confirmed_sales'=>0,'pending_sales'=>1],'pending_financial_reconciliation'],
    [['status'=>'no_confirmed_sales','confirmed_sales'=>0,'pending_sales'=>0],'no_confirmed_sales'],
    [['status'=>'partial','confirmed_sales'=>2,'pending_sales'=>1],'partial'],
    [['status'=>'ambiguous_erp','pending_sales'=>1],'ambiguous_erp'],
] as [$row,$expected]) {
    if (SvAmazonMarginFinancialStatus::forDisplay($row) !== $expected) {
        throw new RuntimeException('legacy_snapshot_display_wrong');
    }
}
try {
    SvAmazonMarginFinancialStatus::classify(-1,0);
    throw new RuntimeException('negative_count_accepted');
} catch (InvalidArgumentException $expected) {}
echo "PASS pending financial Shipment vs zero sales, confirmed/partial and immutable legacy reports\n";
