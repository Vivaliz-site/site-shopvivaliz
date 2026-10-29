<?php
declare(strict_types=1);

// Pure key/claim regression: synthetic input, isolated temporary storage, no network.
$root = getenv('ORDER_TEST_ROOT') ?: dirname(__DIR__);
require_once $root . '/includes/order-idempotency.php';

$failures = 0;
function order_idempotency_check(string $name, bool $condition): void {
    global $failures;
    fwrite($condition ? STDOUT : STDERR, ($condition ? 'PASS: ' : 'FAIL: ') . $name . PHP_EOL);
    if (!$condition) $failures++;
}
$oldRuntime = getenv('SHOPVIVALIZ_RUNTIME_DIR');
$fixtureRuntime = sys_get_temp_dir() . '/shopvivaliz-idempotency-test-' . bin2hex(random_bytes(8));
if (!mkdir($fixtureRuntime, 0700)) throw new RuntimeException('fixture_directory_unavailable');
putenv('SHOPVIVALIZ_RUNTIME_DIR=' . $fixtureRuntime);

try {
    $body = [
        'customer_name' => 'Synthetic Fixture', 'customer_email' => 'fixture@example.invalid',
        'customer_phone' => '(00) 00000-0000', 'cep' => '01310-100',
        'address' => 'Fixture Street', 'street_number' => '1', 'complement' => 'Unit A',
        'neighborhood' => 'Fixture', 'city' => 'Fixture City', 'state' => 'SP',
        'cpf' => '00000000000', 'document_type' => 'cpf',
        'payment_method' => 'mercado_pago', 'shipping_quote_id' => 'synthetic-signed-quote',
        'coupon_code' => 'TEST10', 'coupon' => ['code' => 'TEST10', 'amount' => 4.22],
    ];
    $items = [['sku' => 'FIXTURE-A', 'quantity' => 1, 'price' => 15.47]];
    $key = svoi_key($body, $items);
    order_idempotency_check('Identical checkout keeps its deterministic identity', svoi_key($body, $items) === $key);
    $couponChanged = array_replace($body, ['coupon_code' => 'OTHER', 'coupon' => ['code' => 'OTHER', 'amount' => 1.00]]);
    order_idempotency_check('Changed validated coupon has a distinct identity', svoi_key($couponChanged, $items) !== $key);
    order_idempotency_check('Changed payment method has a distinct identity', svoi_key(array_replace($body, ['payment_method' => 'infinitepay']), $items) !== $key);
    order_idempotency_check('Changed delivery number has a distinct identity', svoi_key(array_replace($body, ['street_number' => '2']), $items) !== $key);
    order_idempotency_check('Changed payer document has a distinct identity', svoi_key(array_replace($body, ['cpf' => '11111111111']), $items) !== $key);
    order_idempotency_check('Changed customer name has a distinct identity', svoi_key(array_replace($body, ['customer_name' => 'Other Fixture']), $items) !== $key);
    order_idempotency_check('Tracking-only changes keep the existing identity', svoi_key(array_replace($body, ['gclid' => 'synthetic-click', 'funnel_client_id' => 'fixture-only']), $items) === $key);
    order_idempotency_check('Customer formatting does not create a second identity', svoi_key(array_replace($body, ['customer_email' => ' FIXTURE@EXAMPLE.INVALID ', 'customer_phone' => '00000000000', 'cep' => '01310100']), $items) === $key);
    $pricedItems = [['sku' => 'FIXTURE-A', 'quantity' => 1, 'price' => 16.00]];
    order_idempotency_check('A changed authoritative item price has a distinct identity', svoi_key($body, $pricedItems) !== $key);
    order_idempotency_check('Explicit caller idempotency key keeps its established semantics', svoi_key(array_replace($body, ['idempotency_key' => 'fixture-explicit-key']), $items) === 'fixture-explicit-key');

    order_idempotency_check('First claim succeeds in the isolated runtime', svoi_claim($key));
    order_idempotency_check('Duplicate claim stays blocked', !svoi_claim($key));
    $changedKey = svoi_key($couponChanged, $items);
    order_idempotency_check('A changed checkout can claim its distinct identity', svoi_claim($changedKey));
    order_idempotency_check('Distinct claim does not release the first checkout lock', !svoi_claim($key));
    svoi_release($key);
    order_idempotency_check('Explicit release retains its existing behavior', svoi_claim($key));
} finally {
    foreach (glob($fixtureRuntime . '/storage/order-idempotency/*.lock') ?: [] as $file) unlink($file);
    @rmdir($fixtureRuntime . '/storage/order-idempotency');
    @rmdir($fixtureRuntime . '/storage');
    @rmdir($fixtureRuntime);
    $oldRuntime === false ? putenv('SHOPVIVALIZ_RUNTIME_DIR') : putenv('SHOPVIVALIZ_RUNTIME_DIR=' . $oldRuntime);
}
exit($failures > 0 ? 1 : 0);
