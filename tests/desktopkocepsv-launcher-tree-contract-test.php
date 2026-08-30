<?php
$root = dirname(__DIR__);
$files = [
    $root . '/scripts/desktopkocepsv-desktop-commander-supervisor.ps1',
    $root . '/scripts/desktopkocepsv-desktop-commander-status.ps1',
];
foreach ($files as $path) {
    if (!is_file($path)) { fwrite(STDERR, "missing: {$path}\n"); exit(1); }
    $text = file_get_contents($path);
    foreach ([
        'Get-LauncherRoots',
        'ParentProcessId',
        '@wonderwhy-er[\\\\/]desktop-commander[\\\\/]dist[\\\\/]index\.js',
        '$canonical = @(Get-CanonicalRemoteLaunchers)',
        '$noncanonical = @(Get-NonCanonicalRemoteLaunchers)',
    ] as $needle) {
        if (stripos($text, $needle) === false) {
            fwrite(STDERR, basename($path) . " missing {$needle}\n");
            exit(1);
        }
    }
}
echo "desktopkocepsv-launcher-tree-contract: ok\n";
