<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$files = [
    $root . '/scripts/vm-desktop-commander-guardian.sh',
    $root . '/ops/systemd/shopvivaliz-desktop-commander-guardian.service',
    $root . '/ops/systemd/shopvivaliz-desktop-commander-guardian.timer',
];
foreach ($files as $f) { if (!is_file($f)) { fwrite(STDERR, "VM guardian artifact missing {$f}\n"); exit(1); } }
$guardian = file_get_contents($files[0]);
$timer = file_get_contents($files[2]);
$installer = file_get_contents($root . '/scripts/install-vm-desktop-commander-service.sh');
$failOpen = '|' . '| true';
if (strpos((string)$guardian, $failOpen) !== false) {
    fwrite(STDERR, "VM guardian contains forbidden fail-open operator\n"); exit(1);
}
foreach (['SERVICE_CGROUP','kill_foreign_launchers','foreign_launcher_removed','auth_blocked'] as $needle) {
    if (strpos((string)$guardian, $needle) === false) { fwrite(STDERR, "VM guardian missing {$needle}\n"); exit(1); }
}
foreach (['OnActiveSec=20s','OnUnitInactiveSec=15s'] as $needle) {
    if (strpos((string)$timer, $needle) === false) { fwrite(STDERR, "VM guardian timer missing {$needle}\n"); exit(1); }
}
foreach (['shopvivaliz-desktop-commander-guardian.service','shopvivaliz-desktop-commander-guardian.timer','vm-desktop-commander-guardian.sh','GUARDIAN_TIMER_ENABLED','GUARDIAN_TIMER_ACTIVE'] as $needle) {
    if (strpos((string)$installer, $needle) === false) { fwrite(STDERR, "VM installer does not manage guardian {$needle}\n"); exit(1); }
}
echo "vm-desktop-commander-guardian-contract: ok\n";
