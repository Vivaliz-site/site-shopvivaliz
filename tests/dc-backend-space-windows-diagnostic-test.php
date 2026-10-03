<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = (string) file_get_contents($root . '/.github/workflows/dc-four-host-login-once.yml');

$required = [
    'Ensure backend auth scratch space',
    'DC_BACKEND_SCRATCH before_kb=',
    'DC_BACKEND_SCRATCH after_kb=',
    'journalctl --vacuum-size=100M',
    'rm -rf "$HOME/.npm/_cacache"',
    "command=\"& { \\\$p='",
    'DC_WINDOWS_AUTH_DIAG host=$host remote_probe=failed',
];

foreach ($required as $needle) {
    if (!str_contains($workflow, $needle)) {
        fwrite(STDERR, "DC backend-space/windows-diagnostic fix missing: {$needle}\n");
        exit(1);
    }
}

if (str_contains($workflow, 'command="& { \\\\$p=')) {
    fwrite(STDERR, "Windows diagnostic command still double-escapes PowerShell variable\n");
    exit(1);
}

echo "dc-backend-space-windows-diagnostic: ok\n";
