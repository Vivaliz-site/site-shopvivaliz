<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$runnerPath = $root . '/scripts/desktopkocepsv-desktop-commander-runner.ps1';
$runner = file_get_contents($runnerPath);
if ($runner === false) {
    fwrite(STDERR, "runner missing\n");
    exit(1);
}
if (strpos($runner, '$RecoverableChannelPattern = \'Channel (closed|errored)\'') === false) {
    fwrite(STDERR, "recoverable channel pattern missing\n");
    exit(1);
}
if (preg_match('/\$DegradedPattern\s*=.*Channel \(closed\|errored\)/', $runner) === 1) {
    fwrite(STDERR, "recoverable channel events still arm degraded restart\n");
    exit(1);
}
foreach ([
    'Provider channel transient event observed; provider kept alive',
    'Write-ConnectionMarker',
] as $needle) {
    if (strpos($runner, $needle) === false) {
        fwrite(STDERR, "runner missing {$needle}\n");
        exit(1);
    }
}
echo "desktopkocepsv-recoverable-channel-contract: ok\n";
