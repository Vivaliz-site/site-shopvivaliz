<?php
$root = dirname(__DIR__);
$secure = file_get_contents($root . '/.github/workflows/vm-desktop-commander-secure-recovery.yml');
$control = file_get_contents($root . '/.github/workflows/desktop-commander-three-host-control-plane.yml');
$all = $secure . "\n" . $control;

foreach (['sudo kill -9', 'VM_KILL_TEST=passed'] as $bad) {
    if (strpos($secure, $bad) !== false) {
        fwrite(STDERR, "dc-nondisruptive: destructive recovery remains: {$bad}\n");
        exit(1);
    }
}
if (strpos($all, 'git merge --ff-only') !== false) {
    fwrite(STDERR, "dc-nondisruptive: broad ff-only merge remains in recovery path\n");
    exit(1);
}
foreach (['git restore --source=origin/main --', 'device.json', 'auth-required.cooldown', '-nt'] as $needle) {
    if (strpos($all, $needle) === false) {
        fwrite(STDERR, "dc-nondisruptive: missing {$needle}\n");
        exit(1);
    }
}
echo "desktop-commander-nondisruptive-recovery: ok\n";
