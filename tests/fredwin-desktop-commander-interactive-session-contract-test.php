<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root . '/scripts/fredwin-desktop-commander-supervisor.ps1';
$s = file_get_contents($path);
if ($s === false) {
    throw new RuntimeException('supervisor missing');
}

$required = [
    'New-ScheduledTaskTrigger -AtLogOn -User $user',
    'New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Highest',
    'Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger @($logon,$watchdog) -Principal $interactivePrincipal',
    'New-ScheduledTaskPrincipal -UserId $user -LogonType S4U -RunLevel Highest',
    'Register-ScheduledTask -TaskName $GuardianTaskName',
    '-Principal $guardianPrincipal',
    '-Principal $primaryPrincipal',
];

foreach ($required as $needle) {
    if (strpos($s, $needle) === false) {
        fwrite(STDERR, "missing interactive-session contract: {$needle}\n");
        exit(1);
    }
}

echo "fredwin-desktop-commander-interactive-session-contract-test: OK\n";
