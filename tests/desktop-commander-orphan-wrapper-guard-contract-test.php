<?php
$path = __DIR__ . '/../scripts/fredwin-desktop-commander-supervisor.ps1';
$src = file_get_contents($path);
$needles = [
    'function Get-OrphanDirectRemoteLaunchers',
    'Get-OrphanDirectRemoteLaunchers',
    'Stopped orphan Desktop Commander direct wrapper pid='
];
foreach ($needles as $needle) {
    if (strpos($src, $needle) === false) {
        fwrite(STDERR, "missing orphan-wrapper guard: {$needle}\n");
        exit(1);
    }
}
echo "desktop-commander-orphan-wrapper-guard: ok\n";
