<?php
declare(strict_types=1);

// Offline regression: a local "paid" flag is not independent gateway evidence.
require_once dirname(__DIR__) . '/includes/order-transaction-evidence.php';

$cases = [
    ['missing gateway / local paid / ERP cancelled', '', 'pagamento_aprovado', '2', 'requires_review'],
    ['pending gateway / local paid / ERP cancelled', 'pending', 'pagamento_aprovado', '2', 'requires_review'],
    ['failed gateway / local paid / ERP cancelled', 'failed', 'pagamento_aprovado', '2', 'requires_review'],
    ['refunded gateway / local paid / ERP cancelled', 'refunded', 'pagamento_aprovado', '2', 'requires_review'],
    ['missing gateway / local paid / ERP approved', '', 'pagamento_aprovado', '3', 'requires_review'],
    ['approved gateway / local paid / ERP cancelled', 'approved', 'pagamento_aprovado', '2', 'discrepancy'],
    ['approved gateway / local paid / ERP not delivered', 'approved', 'pagamento_aprovado', '9', 'discrepancy'],
    ['approved gateway / local paid / ERP invoiced', 'approved', 'pagamento_aprovado', '1', 'matched'],
    ['approved gateway / local pending / ERP cancelled', 'approved', 'aguardando_pagamento', '2', 'discrepancy'],
    ['approved gateway / local pending / ERP open', 'approved', 'aguardando_pagamento', '0', 'requires_review'],
    ['pending gateway / local pending / ERP cancelled', 'pending', 'aguardando_pagamento', '2', 'matched'],
    ['missing gateway / local pending / ERP open', '', 'aguardando_pagamento', '0', 'pending'],
    ['refunded gateway / local cancelled / ERP cancelled', 'refunded', 'cancelado', '2', 'matched'],
];

foreach ($cases as [$name, $provider, $local, $erp, $expected]) {
    $result = svote_reconciliation_state_with_local_order($provider, $local, $erp);
    if ($result !== $expected) {
        fwrite(STDERR, 'FAIL ' . $name . ': expected ' . $expected . ', got ' . $result . PHP_EOL);
        exit(1);
    }
}

$source = (string)file_get_contents(dirname(__DIR__) . '/includes/order-transaction-evidence.php');
if (!str_contains($source, 'SELECT payment_provider_status, order_status FROM orders')) {
    fwrite(STDERR, 'FAIL reconciliation must inspect both independent and local states' . PHP_EOL);
    exit(1);
}
if (!str_contains($source, 'svote_reconciliation_state_with_local_order($paymentStatus, $localStatus, $erpState)')) {
    fwrite(STDERR, 'FAIL live reconciliation must call evidence-aware classifier' . PHP_EOL);
    exit(1);
}
echo "PASS: 13 local-vs-provider-vs-ERP reconciliation fixtures.\n";
