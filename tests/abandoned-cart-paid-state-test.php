<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Commerce/AbandonedCartRecovery.php';

$completed = [
    'pagamento_aprovado',
    'nota_fiscal_enviada',
    'pronto_para_enviar',
    'enviado',
    'entregue',
    'nao_entregue',
];
foreach ($completed as $status) {
    if (!svacr_status_is_completed($status)) {
        fwrite(STDERR, "Expected completed status: {$status}\n");
        exit(1);
    }
}

foreach (['aguardando_pagamento', 'payment_pending', 'cancelado', 'devolvido', ''] as $status) {
    if (svacr_status_is_completed($status)) {
        fwrite(STDERR, "Pending/failed status must remain recoverable: {$status}\n");
        exit(1);
    }
}

$script = file_get_contents(dirname(__DIR__) . '/scripts/send-abandoned-cart-emails.php');
if (!is_string($script) || $script === '') {
    fwrite(STDERR, "Unable to read abandoned cart sender\n");
    exit(1);
}
if (!str_contains($script, 'svacr_reconcile_completed_orders($pdo)')) {
    fwrite(STDERR, "Sender must reconcile paid orders before selecting candidates\n");
    exit(1);
}
if (str_contains($script, 'NOT EXISTS (')) {
    fwrite(STDERR, "Sender must not suppress recovery merely because any order exists\n");
    exit(1);
}
if (!str_contains($script, 'recovered_at IS NULL')) {
    fwrite(STDERR, "Sender must require an unrecovered abandonment\n");
    exit(1);
}

fwrite(STDOUT, "abandoned-cart-paid-state-test: ok\n");
