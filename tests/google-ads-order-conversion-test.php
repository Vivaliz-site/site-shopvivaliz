<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/google-ads-order-conversion.php';

function assert_true(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$order = [
    'order_number' => 'SV202610060001',
    'total' => 71.56,
    'gclid' => 'abc_DEF-123.ghi',
    'gbraid' => 'ignored-braid',
    'payment_evidence_at' => '2026-10-06T12:30:45-03:00',
];
$payload = svgads_build_click_conversion($order, 'customers/123/conversionActions/456');
assert_true(is_array($payload), 'payload should be built when a click id exists');
assert_true(($payload['gclid'] ?? '') === 'abc_DEF-123.ghi', 'gclid should be preferred');
assert_true(!isset($payload['gbraid']) && !isset($payload['wbraid']), 'only one click identifier should be sent');
assert_true(($payload['conversionAction'] ?? '') === 'customers/123/conversionActions/456', 'conversion action resource should be preserved');
assert_true(($payload['conversionValue'] ?? null) === 71.56, 'conversion value should use paid order total');
assert_true(($payload['currencyCode'] ?? '') === 'BRL', 'currency should be BRL');
assert_true(($payload['orderId'] ?? '') === 'SV202610060001', 'order id should deduplicate uploads');
assert_true(($payload['conversionDateTime'] ?? '') === '2026-10-06 12:30:45-03:00', 'payment approval time should be formatted for Google Ads');

$braidOrder = [
    'order_number' => 'SV202610060002',
    'total' => 20.00,
    'wbraid' => 'wbraid-123',
    'created_at' => '2026-10-06T10:00:00-03:00',
];
$braidPayload = svgads_build_click_conversion($braidOrder, 'customers/123/conversionActions/456');
assert_true(($braidPayload['wbraid'] ?? '') === 'wbraid-123', 'wbraid should be accepted when gclid is absent');

$organic = [
    'order_number' => 'SV202610060003',
    'total' => 50.00,
    'payment_evidence_at' => '2026-10-06T12:00:00-03:00',
];
assert_true(svgads_build_click_conversion($organic, 'customers/123/conversionActions/456') === null, 'organic orders must not upload click conversions');

$invalid = $order;
$invalid['gclid'] = 'bad id with spaces';
$invalid['gbraid'] = '';
assert_true(svgads_build_click_conversion($invalid, 'customers/123/conversionActions/456') === null, 'invalid click identifiers must fail closed');

echo "PASS: Google Ads approved-order conversion payload is deterministic and attributable.\n";
