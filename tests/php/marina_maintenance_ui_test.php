<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$page = $root . '/admin/marina-maintenance.php';

function marina_ui_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

marina_ui_assert(is_file($page), 'Marina maintenance admin page must exist');
$source = (string)file_get_contents($page);
foreach ([
    "includes/admin-guard.php",
    "includes/csrf.php",
    "marina-maintenance-status.php",
    "marina-maintenance-preview.php",
    "marina-maintenance-approve.php",
    "marina-maintenance-operation.php",
    'signer',
    'one-click',
    'disabled',
    'NAO_APTO',
    'sem ticket ou assinatura',
] as $marker) {
    marina_ui_assert(str_contains($source, $marker), "UI must contain protected marker: {$marker}");
}
marina_ui_assert(!str_contains($source, 'id="ticket"'), 'UI must not expose a ticket input');
marina_ui_assert(!str_contains($source, 'id="signature"'), 'UI must not expose a signature input');
marina_ui_assert(!str_contains($source, 'signature_b64'), 'UI must not serialize detached signatures');
marina_ui_assert(str_contains($source, 'body: \'{}\''), 'one-click approval must not send browser-supplied authority');
marina_ui_assert(str_contains($source, "signerState === 'READY'"), 'one-click approval must stay disabled until signer reports ready');
marina_ui_assert(str_contains($source, 'viewport') && str_contains($source, 'min-height:44px'), 'mobile one-click controls must have viewport and touch target contracts');
marina_ui_assert(str_contains($source, 'maxPollAttempts = 20'), 'running operation polling must be bounded');
marina_ui_assert(str_contains($source, 'Acompanhamento indisponível'), 'operation polling failure must not trigger a retrying approval');
marina_ui_assert(!preg_match('/(?:ssh|shell_exec|passthru|proc_open|system\s*\(|runtime_lock|crontab|systemctl)/i', $source), 'UI must not contain direct host mutation');

fwrite(STDOUT, "marina_maintenance_ui_test: OK\n");
