<?php
declare(strict_types=1);

// Fixture-only regression. Never calls gateways, creates orders or writes MySQL.
require_once dirname(__DIR__) . '/includes/financial-drift-classifier.php';

function fd_assert(string $expected, array $order, array $payment, array $erp, string $case): void
{
    $actual = svfd_classify($order, $payment, $erp);
    if ($actual !== $expected) {
        fwrite(STDERR, 'FAIL ' . $case . ': expected ' . $expected . ' got ' . $actual . PHP_EOL);
        exit(1);
    }
}

$order = [
    'source' => 'site_checkout_validated',
    'order_number' => 'SV-FIXTURE-001',
    'status' => 'payment_approved',
    'total' => 49.90,
];
$payment = [
    'status' => 'approved',
    'external_reference' => 'SV-FIXTURE-001',
    'transaction_amount' => 49.90,
    'currency_id' => 'BRL',
    'transaction_amount_refunded' => 0.00,
    'refunds' => [],
];
fd_assert('discrepancy', $order, $payment, ['situacao' => 2], 'approved money + cancelled ERP');
fd_assert('discrepancy', $order, $payment, ['situacao' => '2'], 'ERP status supplied as string');
fd_assert('matched', $order, $payment, ['situacao' => 1], 'approved money + invoiced ERP');
fd_assert('pending', $order, $payment, ['situacao' => 0], 'approved money + open ERP');
fd_assert('pending', $order, $payment, ['situacao' => 8], 'approved money + incomplete ERP');
fd_assert('requires_review', $order, array_replace($payment, ['transaction_amount_refunded' => 49.90]), ['situacao' => 2], 'refunded payment not classified unrefunded');
fd_assert('requires_review', $order, array_replace($payment, ['refunds' => [['status' => 'approved']]]), ['situacao' => 2], 'refund details require review');
fd_assert('unverified', $order, array_replace($payment, ['external_reference' => 'SV-OTHER']), ['situacao' => 2], 'different order reference');
fd_assert('unverified', $order, array_replace($payment, ['currency_id' => 'USD']), ['situacao' => 2], 'different currency');
fd_assert('unverified', $order, array_replace($payment, ['transaction_amount' => 39.90]), ['situacao' => 2], 'different amount');
fd_assert('unverified', $order, array_replace($payment, ['status' => 'pending']), ['situacao' => 2], 'not approved remotely');
fd_assert('unverified', $order, $payment, ['situacao' => null], 'no ERP authority');
fd_assert('discrepancy', array_replace($order, ['status' => 'payment_pending']), $payment, ['situacao' => 3], 'remote approved while local pending');
fd_assert('excluded', array_replace($order, ['test_order' => true]), $payment, ['situacao' => 2], 'test orders excluded');
fd_assert('excluded', array_replace($order, ['source' => 'mercado_livre']), $payment, ['situacao' => 2], 'marketplace orders excluded');
fd_assert('unverified', array_replace($order, ['order_number' => '']), $payment, ['situacao' => 2], 'missing local reference');

$runtime = (string)file_get_contents(dirname(__DIR__) . '/scripts/finance/site-payment-drift-readonly.php');
foreach (['svmp_api_request', 'svtop_tiny_get_order', 'SELECT ', 'source', 'test_order'] as $needle) {
    if (!str_contains($runtime, $needle)) {
        fwrite(STDERR, 'FAIL missing read-only evidence source ' . $needle . PHP_EOL);
        exit(1);
    }
}
if (preg_match('/\\b(UPDATE|INSERT|DELETE|REPLACE|POST|PUT|PATCH)\\s+(orders|\\/pedidos)/i', $runtime)) {
    fwrite(STDERR, 'FAIL detected payment/order mutation in read-only reporter' . PHP_EOL);
    exit(1);
}
echo "PASS: 16 financial drift fixtures, read-only contract.\n";
