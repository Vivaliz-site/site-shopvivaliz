<?php
$root = dirname(__DIR__);
$supervisor = file_get_contents($root . '/scripts/vm-desktop-commander-supervisor.sh');
$unit = file_get_contents($root . '/ops/systemd/shopvivaliz-desktop-commander.service');
$needles = [
    'device_state_newer_than_cooldown',
    'find_competing_remote_sessions',
    'terminate_competing_remote_sessions',
    'REMOTE_OWNER_PID',
    'REMOTE_OWNER_SESSION',
    'provider-connected.marker',
];
foreach ($needles as $needle) {
    if (strpos($supervisor, $needle) === false) {
        fwrite(STDERR, "vm-dc-single-owner: missing {$needle}\n");
        exit(1);
    }
}
if (strpos($unit, 'RestartPreventExitStatus=20') === false) {
    fwrite(STDERR, "vm-dc-single-owner: missing fail-closed auth guard\n");
    exit(1);
}
echo "vm-dc-single-owner: ok\n";
