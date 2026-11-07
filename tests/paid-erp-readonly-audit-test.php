<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/quality/audit-paid-erp-readonly.php';

function svfra_test(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
}

$reference = 'SV' . str_repeat('1', 17);
$approved = [
    'status' => 'approved',
    'external_reference' => $reference,
    'transaction_amount' => 52.50,
    'transaction_amount_refunded' => 0.0,
    'refunds' => [],
];
svfra_test(svfra_approved_payment($approved, $reference, 52.50), 'official matching approved unrefunded payment');
foreach ([
    ['status' => 'pending'],
    ['external_reference' => 'SV' . str_repeat('2', 17)],
    ['transaction_amount' => 52.75],
    ['transaction_amount_refunded' => 1.0],
    ['refund_count' => 1],
    ['refunds' => [['id' => 'refund-example']]],
] as $change) {
    svfra_test(!svfra_approved_payment(array_replace($approved, $change), $reference, 52.50), 'never trust unmatched, pending or refunded payment');
}
svfra_test(!svfra_approved_payment($approved, $reference, 0.0), 'zero local order total fails closed');

foreach ([
    '2' => 'discrepancy', // Cancelada
    '9' => 'discrepancy', // Não Entregue
    '0' => 'pending', // Aberta
    '3' => 'matched', // Aprovada
    '6' => 'matched', // Entregue
] as $tiny => $expected) {
    $reply = ['status' => 200, 'json' => ['data' => ['situacao' => (int)$tiny]]];
    $status = svfra_erp_status($reply);
    svfra_test($status === (string)$tiny, "Tiny official v3 parser situacao {$tiny}");
    svfra_test(svote_reconciliation_state('approved', (string)$status) === $expected, "MP approval + Tiny {$tiny} => {$expected}");
}
svfra_test(svfra_erp_status(['status' => 200, 'json' => ['situacao' => 2]]) === '2', 'direct response shape');
svfra_test(svfra_erp_status(['status' => 401, 'json' => ['data' => ['situacao' => 2]]]) === null, 'unverified ERP transport fails closed');
svfra_test(svfra_erp_status(['status' => 200, 'json' => ['data' => []]]) === null, 'missing ERP status fails closed');

$script = (string)file_get_contents(dirname(__DIR__) . '/scripts/quality/audit-paid-erp-readonly.php');
svfra_test(str_contains($script, "svmp_api_request('GET'"), 'provider verification is read-only GET');
svfra_test(str_contains($script, 'svtop_tiny_get_order('), 'ERP verification is read-only GET');
svfra_test(str_contains($script, 'SELECT order_number, order_status, payment_provider_id, olist_order_id'), 'DB read is SELECT only');
svfra_test(!str_contains($script, 'UPDATE orders') && !str_contains($script, 'DELETE FROM orders'), 'no order database mutations in audit');
svfra_test(!str_contains($script, "svote_record_reconciliation(") && !str_contains($script, "svote_record_payment("), 'no evidence or payment backfill from audit');

fwrite(STDOUT, "PASS: read-only official MP/Olist mismatches, refund/reference guard and Tiny status fixture.\n");
