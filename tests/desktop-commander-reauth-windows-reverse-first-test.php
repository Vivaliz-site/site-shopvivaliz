<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = (string) file_get_contents($root . '/.github/workflows/desktop-commander-reauth-runner.yml');

$required = [
    'backend_port_alive "$reverse_port"',
    'reverse_port=2222',
    'reverse_port=2223',
    'target_host=127.0.0.1',
    'DC_REAUTH_WINDOWS_ROUTE',
    "-AuthPackageVersion '0.2.51'",
];

foreach ($required as $needle) {
    if (!str_contains($workflow, $needle)) {
        fwrite(STDERR, "DC reauth Windows reverse-first contract missing: {$needle}\n");
        exit(1);
    }
}

echo "desktop-commander-reauth-windows-reverse-first: ok\n";
