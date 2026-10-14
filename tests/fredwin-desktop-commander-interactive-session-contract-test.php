<?php
$root = dirname(__DIR__);
$s = file_get_contents($root . '/scripts/fredwin-desktop-commander-supervisor.ps1');
if ($s === false) { throw new RuntimeException('supervisor missing'); }
$checks = [
    '$startup = New-ScheduledTaskTrigger -AtStartup',
    '-LogonType S4U -RunLevel Highest',
    '-Trigger @($startup,$watchdog) -Principal $principal',
    '-Trigger @($guardianStartup,$guardianWatchdog) -Principal $principal',
    'logon=S4U watchdog=5m guardian=15m',
];
foreach ($checks as $needle) {
    if (strpos($s, $needle) === false) { fwrite(STDERR, "missing: {$needle}\n"); exit(1); }
}
$forbidden = ['-AtLogOn -User $user', 'LogonType Interactive', 'primary_logon=Interactive'];
foreach ($forbidden as $needle) {
    if (strpos($s, $needle) !== false) { fwrite(STDERR, "forbidden stale Fred-Win session contract: {$needle}\n"); exit(1); }
}
echo "fredwin-desktop-commander-s4u-session-contract-test: OK\n";
