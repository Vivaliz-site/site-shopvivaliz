<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$path = $root . '/scripts/local-auto-sync.ps1';
$s = file_get_contents($path);
if ($s === false) { fwrite(STDERR, "local-auto-sync missing\n"); exit(1); }
foreach (['fredwin-desktop-commander-supervisor.ps1','fredwin-remote-bootstrap.ps1','-Mode InstallTask'] as $needle) {
    if (stripos($s, $needle) !== false) { fwrite(STDERR, "local-auto-sync still owns 24h runtime: {$needle}\n"); exit(1); }
}
if (strpos($s, 'DC_AND_RELAY_OWNED_BY_DEDICATED_WATCHDOGS=true') === false) {
    fwrite(STDERR, "local-auto-sync ownership marker missing\n"); exit(1);
}
echo "local-auto-sync-dc-separation-contract: ok\n";