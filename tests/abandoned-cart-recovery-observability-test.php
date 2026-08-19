<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$schema = file_get_contents($root . '/src/Commerce/AbandonedCartRecoverySchema.php');
$restore = file_get_contents($root . '/api/checkout/restore-abandonment.php');
$report = file_get_contents($root . '/scripts/report-abandoned-cart-recovery.php');

foreach ([$schema, $restore, $report] as $source) {
    if (!is_string($source) || $source === '') {
        fwrite(STDERR, "Unable to read recovery observability source\n");
        exit(1);
    }
}

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};

$expect(str_contains($schema, 'restored_at DATETIME NULL'), 'restored_at schema missing');
$expect(str_contains($restore, 'SET restored_at = COALESCE(restored_at, NOW())'), 'restore must mark first successful restoration idempotently');
$emptyGuardPos = strpos($restore, 'if ($items === [])');
$markPos = strpos($restore, 'SET restored_at = COALESCE');
$expect($emptyGuardPos !== false && $markPos !== false && $emptyGuardPos < $markPos, 'restore must not mark before validating sellable items');
$expect(str_contains($report, 'email_to_restore_rate_pct'), 'report must expose email-to-restore rate');
$expect(str_contains($report, 'email_to_purchase_rate_pct'), 'report must expose email-to-purchase rate');
$expect(str_contains($report, 'restore_to_purchase_rate_pct'), 'report must expose restore-to-purchase rate');
$expect(!str_contains($report, 'SELECT email'), 'aggregate report must not select email');
$expect(!str_contains($report, 'customer_name'), 'aggregate report must not expose customer name');
$expect(!str_contains($report, 'cart_snapshot'), 'aggregate report must not expose cart snapshot');

fwrite(STDOUT, "abandoned-cart-recovery-observability-test: ok\n");