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

$schema = (string)file_get_contents(dirname(__DIR__) . '/includes/account-schema.php');
foreach (['payment_provider_status', 'payment_provider_id', 'payment_evidence_json', 'reconciliation_status', 'reconciliation_checked_at'] as $column) {
    otr_assert(str_contains($schema, $column), "orders schema must include {$column}");
}

$dispatcher = (string)file_get_contents(dirname(__DIR__) . '/includes/webhook-job-dispatcher.php');
otr_assert(str_contains($dispatcher, "require_once __DIR__ . '/order-transaction-evidence.php';"), 'webhook dispatcher must load transaction evidence helper');
otr_assert(str_contains($dispatcher, 'svote_record_payment('), 'Mercado Pago webhook must persist provider evidence');
otr_assert(str_contains($dispatcher, 'svote_record_reconciliation('), 'ERP push must persist reconciliation state');

fwrite(STDOUT, "PASS: order transaction evidence and reconciliation contract.\n");

otr_assert(substr_count($dispatcher, 'svote_record_payment(') >= 2, 'both payment gateways must persist provider evidence');
otr_assert(substr_count($dispatcher, 'svote_record_reconciliation(') >= 2, 'both payment gateways must persist ERP reconciliation state');
