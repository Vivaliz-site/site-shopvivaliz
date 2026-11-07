<?php
declare(strict_types=1);

function otr_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$helper = dirname(__DIR__) . '/includes/order-transaction-evidence.php';
otr_assert(is_file($helper), 'order transaction evidence helper must exist');
require_once $helper;

otr_assert(function_exists('svote_reconciliation_state'), 'reconciliation state helper must exist');
otr_assert(svote_reconciliation_state('approved', 'cancelled') === 'discrepancy', 'approved payment + cancelled ERP must be a discrepancy');
otr_assert(svote_reconciliation_state('approved', 'open') === 'pending', 'approved payment + open ERP must remain pending reconciliation');
otr_assert(svote_reconciliation_state('approved', 'invoiced') === 'matched', 'approved payment + invoiced ERP must reconcile');
otr_assert(svote_reconciliation_state('pending', 'cancelled') === 'matched', 'pending payment + cancelled ERP is not a paid-state discrepancy');
// Canonical Tiny/Olist v3 situacao enums: 0=open, 1=invoiced, 2=cancelled,
// 3=approved, 4=preparing, 5=sent, 6=delivered, 7=ready, 8=incomplete,
// 9=not-delivered. A paid + cancelled order must NEVER be counted as matched.
$expectedByTinyStatus = [
    '0' => 'pending',
    '1' => 'matched',
    '2' => 'discrepancy',
    '3' => 'matched',
    '4' => 'matched',
    '5' => 'matched',
    '6' => 'matched',
    '7' => 'matched',
    '8' => 'pending',
    '9' => 'discrepancy',
];
foreach ($expectedByTinyStatus as $tinyStatus => $expected) {
    otr_assert(
        svote_reconciliation_state('approved', (string)$tinyStatus) === $expected,
        "approved payment + Tiny situacao={$tinyStatus} must be {$expected}"
    );
}
foreach (['cancelada', 'cancelado', 'nao_entregue', 'não entregue'] as $cancelledLabel) {
    otr_assert(svote_reconciliation_state('pagamento_aprovado', $cancelledLabel) === 'discrepancy', "paid local order + {$cancelledLabel} must be a discrepancy");
}
otr_assert(svote_reconciliation_state('pagamento_aprovado', '2') === 'discrepancy', 'legacy paid MySQL + Tiny numeric cancelled must be discrepancy');
otr_assert(svote_reconciliation_state('pending', '2') === 'matched', 'pending payment + Tiny cancelled order is not paid mismatch');
otr_assert(svote_reconciliation_state('approved', 'unrecognized_status') === 'pending', 'unknown ERP status must fail closed as pending');


$schema = (string)file_get_contents(dirname(__DIR__) . '/includes/account-schema.php');
foreach (['payment_provider_status', 'payment_provider_id', 'payment_evidence_json', 'reconciliation_status', 'reconciliation_checked_at'] as $column) {
    otr_assert(str_contains($schema, $column), "orders schema must include {$column}");
}

$dispatcher = (string)file_get_contents(dirname(__DIR__) . '/includes/webhook-job-dispatcher.php');
otr_assert(str_contains($dispatcher, "require_once __DIR__ . '/order-transaction-evidence.php';"), 'webhook dispatcher must load transaction evidence helper');
otr_assert(str_contains($dispatcher, 'svote_record_payment('), 'Mercado Pago webhook must persist provider evidence');
otr_assert(!str_contains($dispatcher, 'svote_record_reconciliation('), 'payment webhook must not invent ERP state from a push response');

otr_assert(substr_count($dispatcher, 'svote_record_payment(') >= 2, 'both payment gateways must persist provider evidence');
otr_assert(!str_contains($dispatcher, "'erp_status' => 'open'"), 'both gateway retries must preserve verified ERP state');

fwrite(STDOUT, "PASS: order transaction evidence and reconciliation contract including Tiny v3 status enums.\n");
