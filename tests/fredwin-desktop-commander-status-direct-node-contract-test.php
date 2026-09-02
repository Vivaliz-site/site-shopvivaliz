<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$statusPath = $root . '/scripts/fredwin-desktop-commander-status.ps1';
if (!is_file($statusPath)) { fwrite(STDERR, "Fred status script missing\n"); exit(1); }
$status = (string) file_get_contents($statusPath);
$required = [
    'Test-CanonicalRemoteLauncher',
    'Test-LauncherOwnedByRunner',
    'fredwin-desktop-commander-runner\\.ps1',
    '@wonderwhy-er[\\\\/]desktop-commander[\\\\/]dist[\\\\/]index\\.js',
];
foreach ($required as $needle) {
    if (strpos($status, $needle) === false) {
        fwrite(STDERR, "Fred status missing direct managed node detection: {$needle}\n");
        exit(1);
    }
}
echo "fredwin-desktop-commander-status-direct-node-contract: ok\n";
