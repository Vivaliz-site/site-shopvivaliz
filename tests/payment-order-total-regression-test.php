<?php
declare(strict_types=1);

// Pure payload regression: no API, database, order creation or payment calls.
// Run with PAYMENT_TEST_ROOT pointing at a full isolated checkout.
$root = rtrim((string)(getenv('PAYMENT_TEST_ROOT') ?: dirname(__DIR__)), '/');
require_once $root . '/includes/mercadopago-gateway.php';
require_once $root . '/includes/infinitepay-gateway.php';

// Keep the payload deterministic without reading or printing a live webhook key.
putenv('INFINITEPAY_WEBHOOK_SECRET=payment-payload-fixture');
$_ENV['INFINITEPAY_WEBHOOK_SECRET'] = 'payment-payload-fixture';

$passed = 0;
$failed = 0;
function paymentParityTest(string $name, callable $test): void
{
    global $passed, $failed;
    try {
        $test();
        $passed++;
        echo 'PASS ', $name, PHP_EOL;
    } catch (Throwable $error) {
        $failed++;
        echo 'FAIL ', $name, ': ', $error->getMessage(), PHP_EOL;
    }
}

function paymentParityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function paymentParityLines(string $gateway, array $order): array
{
    $payload = $gateway === 'mp' ? svmp_preference_payload($order) : svip_link_payload($order);
    return array_map(static function (array $line) use ($gateway): array {
        return [
            'name' => $gateway === 'mp' ? $line['title'] : $line['description'],
            'quantity' => $line['quantity'],
            'cents' => $gateway === 'mp' ? (int)round($line['unit_price'] * 100) : $line['price'],
            'sku' => $gateway === 'mp' ? ($line['external_code'] ?? '') : null,
        ];
    }, $payload['items']);
}

$base = [
    'order_number' => 'SV20261029120000123',
    'items' => [
        ['sku' => 'TEST-A', 'name' => 'Produto A', 'quantity' => 1, 'price' => 15.47],
        ['sku' => 'TEST-B', 'name' => 'Produto B', 'quantity' => 1, 'price' => 28.00],
    ],
    'shipping_total' => 13.58,
    'shipping_label' => 'Entrega de teste',
    'customer' => [],
];
$cases = [
    'without_discount' => [array_replace($base, ['total' => 57.05]), 5705],
    'bundle_only' => [array_replace($base, ['buy_together_discount' => 1.30, 'total' => 55.75]), 5575],
    'coupon_only' => [array_replace($base, ['coupon_discount' => 4.35, 'total' => 52.70]), 5270],
    'bundle_and_coupon' => [array_replace($base, ['buy_together_discount' => 1.30, 'coupon_discount' => 4.22, 'total' => 51.53]), 5153],
    'quantity_rounding' => [array_replace($base, [
        'items' => [['sku' => 'TEST-C', 'name' => 'Produto C', 'quantity' => 3, 'price' => 9.99]],
        'shipping_total' => 5.00, 'coupon_discount' => 1.00, 'total' => 33.97,
    ]), 3397],
    'several_quantities_and_cents' => [array_replace($base, [
        'items' => [
            ['sku' => 'TEST-D', 'name' => 'Produto D', 'quantity' => 3, 'price' => 0.10],
            ['sku' => 'TEST-E', 'name' => 'Produto E', 'quantity' => 2, 'price' => 0.25],
        ],
        'shipping_total' => 0, 'coupon_discount' => 0.11, 'total' => 0.69,
    ]), 69],
    'largest_remainder_tie' => [array_replace($base, [
        'items' => [
            ['sku' => 'TEST-D', 'name' => 'Produto D', 'quantity' => 1, 'price' => 1.00],
            ['sku' => 'TEST-E', 'name' => 'Produto E', 'quantity' => 1, 'price' => 1.00],
            ['sku' => 'TEST-F', 'name' => 'Produto F', 'quantity' => 1, 'price' => 1.00],
        ],
        'shipping_total' => 0, 'coupon_discount' => 0.01, 'total' => 2.99,
    ]), 299],
    'minimum_positive_units' => [array_replace($base, [
        'items' => [
            ['sku' => 'TEST-D', 'name' => 'Produto D', 'quantity' => 5, 'price' => 0.01],
            ['sku' => 'TEST-E', 'name' => 'Produto E', 'quantity' => 1, 'price' => 9.99],
        ],
        'shipping_total' => 2, 'coupon_discount' => 9.98, 'total' => 2.06,
    ]), 206],
    'decimal_strings' => [array_replace($base, [
        'items' => [['sku' => 'TEST-C', 'name' => 'Produto C', 'quantity' => '3', 'price' => '9.99']],
        'shipping_total' => '5.00', 'coupon_discount' => '1.00', 'total' => '33.97',
    ]), 3397],
    'authoritative_total_without_recomputing_promotions' => [array_replace($base, [
        'total' => 51.53,
    ]), 5153],
];

foreach (['mp', 'infinitepay'] as $gateway) {
    foreach ($cases as $name => [$order, $expectedCents]) {
        paymentParityTest($gateway . '_' . $name, static function () use ($gateway, $order, $expectedCents): void {
            $before = $order;
            $lines = paymentParityLines($gateway, $order);
            $sum = 0;
            $quantities = [];
            $skuQuantities = [];
            foreach ($lines as $line) {
                paymentParityAssert(is_int($line['quantity']) && $line['quantity'] > 0, 'quantity must be a positive integer');
                paymentParityAssert(is_int($line['cents']) && $line['cents'] > 0, 'unit price must be positive cents');
                $sum += $line['quantity'] * $line['cents'];
                $quantities[$line['name']] = ($quantities[$line['name']] ?? 0) + $line['quantity'];
                if ($gateway === 'mp') {
                    $skuQuantities[$line['sku']] = ($skuQuantities[$line['sku']] ?? 0) + $line['quantity'];
                }
            }
            paymentParityAssert($sum === $expectedCents, 'expected cents ' . $expectedCents . ', got ' . $sum);
            $expectedQuantities = [];
            $expectedSkus = [];
            foreach ($order['items'] as $item) {
                $expectedQuantities[$item['name']] = ($expectedQuantities[$item['name']] ?? 0) + (int)$item['quantity'];
                $expectedSkus[$item['sku']] = ($expectedSkus[$item['sku']] ?? 0) + (int)$item['quantity'];
            }
            $shippingCents = (int)round((float)$order['shipping_total'] * 100);
            if ($shippingCents > 0) {
                $expectedQuantities[$order['shipping_label']] = 1;
                $expectedSkus['shipping_fee'] = 1;
                $shippingLines = array_values(array_filter($lines, static fn(array $line): bool => $line['name'] === $order['shipping_label']));
                paymentParityAssert(count($shippingLines) === 1 && $shippingLines[0]['cents'] === $shippingCents, 'shipping changed');
            }
            ksort($quantities);
            ksort($expectedQuantities);
            paymentParityAssert($quantities === $expectedQuantities, 'product names or total quantities changed');
            if ($gateway === 'mp') {
                ksort($skuQuantities);
                ksort($expectedSkus);
                paymentParityAssert($skuQuantities === $expectedSkus, 'product SKUs changed');
            }
            paymentParityAssert(count($lines) <= 2 * count($order['items']) + ($shippingCents > 0 ? 1 : 0), 'unbounded split lines');
            paymentParityAssert($order === $before, 'stored order or catalog prices mutated');
            paymentParityAssert(paymentParityLines($gateway, $order) === $lines, 'allocation is not deterministic');
        });
    }

    $valid = array_replace($base, ['total' => 57.05]);
    $invalid = [
        'missing_total' => array_diff_key($valid, ['total' => true]),
        'above_gross_total' => array_replace($valid, ['total' => 57.06]),
        'below_shipping' => array_replace($valid, ['total' => 13.57]),
        'nonfinite_total' => array_replace($valid, ['total' => INF]),
        'negative_total' => array_replace($valid, ['total' => -1]),
        'zero_total' => array_replace($valid, ['total' => 0]),
        'insufficient_positive_unit_amounts' => array_replace($valid, ['total' => 13.59]),
        'empty_items' => array_replace($valid, ['items' => []]),
        'invalid_item_not_silently_dropped' => array_replace($valid, ['items' => [$valid['items'][0], null]]),
        'fractional_quantity' => array_replace($valid, ['items' => [['sku' => 'BAD', 'name' => 'Produto', 'price' => 20, 'quantity' => 1.5]]]),
        'zero_quantity' => array_replace($valid, ['items' => [['sku' => 'BAD', 'name' => 'Produto', 'price' => 20, 'quantity' => 0]]]),
        'negative_price' => array_replace($valid, ['items' => [['sku' => 'BAD', 'name' => 'Produto', 'price' => -20, 'quantity' => 1]]]),
    ];
    foreach ($invalid as $name => $order) {
        paymentParityTest($gateway . '_rejects_' . $name, static function () use ($gateway, $order): void {
            try {
                paymentParityLines($gateway, $order);
            } catch (InvalidArgumentException $expected) {
                return;
            }
            throw new RuntimeException('inconsistent order produced a charge payload');
        });
    }
}

echo json_encode(['passed' => $passed, 'failed' => $failed], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failed === 0 ? 0 : 1);
