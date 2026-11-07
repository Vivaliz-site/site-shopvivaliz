<?php
declare(strict_types=1);

/**
 * Financial integrity contract: a successful Tiny create/push returns an ID,
 * NOT a verified current ERP order status. In particular, a retried payment
 * webhook must not overwrite a prior cancelled/discrepant ERP readback with
 * a fabricated "open" status.
 *
 * No test traffic, gateway calls, invoices or database changes.
 */
$dispatcher=(string)file_get_contents(dirname(__DIR__) . '/includes/webhook-job-dispatcher.php');
if ($dispatcher === '') { fwrite(STDERR, "FAIL dispatcher unavailable\n"); exit(1); }
$old = substr_count($dispatcher, "svote_record_reconciliation(");
if ($old !== 0) {
    fwrite(STDERR, "FAIL: $old webhook reconciliation writes without verified ERP state\n");
    exit(1);
}
if (str_contains($dispatcher, "'erp_status' => 'open'")) {
    fwrite(STDERR, "FAIL: webhook hard-codes ERP open status\n");
    exit(1);
}
foreach (['svote_record_payment(', 'olist_order_id = COALESCE', 'sv_webhook_job_run_post_processor('] as $mustKeep) {
    if (!str_contains($dispatcher,$mustKeep)) { fwrite(STDERR,"FAIL missing existing effect $mustKeep\n"); exit(1); }
}
if (substr_count($dispatcher, 'svote_record_payment(') < 2) {
    fwrite(STDERR,"FAIL both gateway evidence paths must be preserved\n");
    exit(1);
}
require_once dirname(__DIR__) . '/includes/order-transaction-evidence.php';
if (svote_reconciliation_state_with_local_order('approved','pagamento_aprovado','2') !== 'discrepancy') {
    fwrite(STDERR, "FAIL authoritative cancelled status must remain discrepant\n");exit(1);
}
echo "PASS: two gateway webhook paths preserve payment evidence and never fabricate ERP state.\n";
