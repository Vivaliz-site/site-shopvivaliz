<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/quality/audit-paid-erp-readonly.php';

function audit_scope_assert(string $expect, ?array $order, string $reference, string $label): void
{
    $actual=svfra_order_scope($order,$reference);
    if ($actual!==$expect) {
        fwrite(STDERR, 'FAIL '.$label.' expect='.$expect.' got='.$actual.PHP_EOL);
        exit(1);
    }
}

$ref='SV20260727000000001';
$paid=['order_number'=>$ref,'source'=>'site_checkout_validated','status'=>'payment_approved','total'=>25.50];
audit_scope_assert('site',$paid,$ref,'site checkout');
audit_scope_assert('test_order',array_replace($paid,['test_order'=>true]),$ref,'explicit test must not count as paid real');
audit_scope_assert('non_site',array_replace($paid,['source'=>'mercado_livre']),$ref,'marketplace not website');
audit_scope_assert('reference_mismatch',array_replace($paid,['order_number'=>'SV-OTHER']),$ref,'independent reference');
audit_scope_assert('missing_archive',null,$ref,'missing local file is coverage gap, never assume transaction failed');
audit_scope_assert('non_site',array_replace($paid,['source'=>'']),$ref,'missing provenance cannot be called site');

$script=(string)file_get_contents(dirname(__DIR__).'/scripts/quality/audit-paid-erp-readonly.php');
foreach(['missing_archive','test_orders_excluded','excluded_non_site','svfra_order_scope('] as $s) {
    if (!str_contains($script,$s)) {
        fwrite(STDERR,"FAIL missing live scope counter $s\n");
        exit(1);
    }
}
echo "PASS: 6 site payment audit scope cases, no financial mutations.\n";
