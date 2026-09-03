<?php
declare(strict_types=1);
$root = dirname(__DIR__);

// Both Windows status scripts must report a real, marker-derived connection
// signal -- not just process/task existence -- so the four-host monitor can
// tell "process alive" apart from "broker actually reachable".
foreach ([
    'fredwin-desktop-commander-status.ps1',
    'desktopkocepsv-desktop-commander-status.ps1',
] as $name) {
    $path = $root . '/scripts/' . $name;
    $text = (string) file_get_contents($path);
    if ($text === '') { fwrite(STDERR, "missing or empty status script: {$name}\n"); exit(1); }
    if (strpos($text, "'PROVIDER_CONNECTED='") === false && strpos($text, "('PROVIDER_CONNECTED=' +") === false) {
        fwrite(STDERR, "{$name} does not report PROVIDER_CONNECTED\n");
        exit(1);
    }
}

// The four-host health workflow must actually require PROVIDER_CONNECTED
// for a Windows host to be reported healthy -- a stale/dead broker
// connection with a live process and task must not be classified healthy
// (the exact "false positive" this fix closes: local process+marker looked
// fine while the device showed offline server-side).
$health = (string) file_get_contents($root . '/.github/workflows/desktop-commander-24h-health.yml');
if ($health === '') { fwrite(STDERR, "missing health workflow\n"); exit(1); }
if (!preg_match('/def win_healthy\(values\):.*?\n\s*\)\n/s', $health, $match)) {
    fwrite(STDERR, "could not locate win_healthy() body\n");
    exit(1);
}
if (strpos($match[0], "is_true(values.get('PROVIDER_CONNECTED'))") === false) {
    fwrite(STDERR, "win_healthy() does not require PROVIDER_CONNECTED\n");
    exit(1);
}
// A structurally healthy host with a disconnected provider must still be
// eligible for repair, not just hosts with a missing process/task.
if (strpos($health, 'not structurally_healthy or not provider_connected') === false) {
    fwrite(STDERR, "repair path does not cover provider-disconnected-but-structurally-healthy hosts\n");
    exit(1);
}
if (strpos($health, "php tests/desktop-commander-provider-connected-health-contract-test.php") === false) {
    fwrite(STDERR, "health workflow does not run this contract test\n");
    exit(1);
}
echo "desktop-commander-provider-connected-health-contract: ok\n";
