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
    'signature_b64',
    'disabled',
    'NAO_APTO',
    'private key',
] as $marker) {
    marina_ui_assert(str_contains($source, $marker), "UI must contain protected marker: {$marker}");
}
marina_ui_assert(!preg_match('/(?:ssh|shell_exec|passthru|proc_open|system\s*\(|runtime_lock|crontab|systemctl)/i', $source), 'UI must not contain direct host mutation');

fwrite(STDOUT, "marina_maintenance_ui_test: OK\n");
