<?php
$root = dirname(__DIR__);
$path = $root . '/scripts/fredwin-desktop-commander-runner.ps1';
$runner = file_get_contents($path);
if ($runner === false) { fwrite(STDERR, "transient-auth: runner missing\n"); exit(1); }
$start = strpos($runner, 'if ($newText -match $AuthPattern)');
$end = $start === false ? false : strpos($runner, 'if ($newText -match $ReadyPattern)', $start);
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "transient-auth: auth decision block not found\n");
    exit(1);
}
$block = substr($runner, $start, $end - $start);
if (strpos($block, 'if ($connected)') === false) {
    fwrite(STDERR, "transient-auth: connected provider is not protected from a late auth signal\n");
    exit(1);
}
if (strpos($block, 'elseif (-not $authRequired)') === false) {
    fwrite(STDERR, "transient-auth: startup auth path is not preserved after connected guard\n");
    exit(1);
}
if (strpos($block, 'AUTH_SIGNAL_IGNORED') === false) {
    fwrite(STDERR, "transient-auth: ignored late auth signal is not observable\n");
    exit(1);
}
echo "fredwin-desktop-commander-transient-auth: ok\n";
