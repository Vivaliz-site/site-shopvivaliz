<?php
declare(strict_types=1);

$workflowPath = __DIR__ . '/../.github/workflows/fred-win-remote-action.yml';
$workflow = (string) @file_get_contents($workflowPath);
if ($workflow === '') {
    fwrite(STDERR, "Fred-Win remote workflow missing\n");
    exit(1);
}

$errors = [];
if (!preg_match('/audit_fredwin_runtime\)\s*\n(?<block>.*?)(?=\n\s*;;)/s', $workflow, $match)) {
    $errors[] = 'audit_fredwin_runtime allowlisted action missing';
} else {
    $block = (string) ($match['block'] ?? '');
    foreach ([
        'Get-CimInstance Win32_Process',
        'Get-ScheduledTask',
        'Get-CimInstance Win32_Service',
        'Get-ItemProperty',
        'Get-NetTCPConnection',
        'Win32_OperatingSystem',
        'ConvertTo-Json',
        'FREDWIN_RUNTIME_AUDIT_BEGIN',
        'FREDWIN_RUNTIME_AUDIT_END',
    ] as $needle) {
        if (!str_contains($block, $needle)) {
            $errors[] = 'runtime audit missing read-only inventory primitive: ' . $needle;
        }
    }

    if (!preg_match('/\\\\?\$ownerPid=/', $block)) {
        $errors[] = 'runtime audit must use ownerPid instead of PowerShell automatic PID variable';
    }

    foreach (['LastRunTime', 'NextRunTime'] as $taskDateProperty) {
        $nullGuard = '\\$null -ne \\$info.' . $taskDateProperty;
        if (!str_contains($block, $nullGuard)) {
            $errors[] = 'runtime audit must null-guard scheduled task date before ToString: ' . $taskDateProperty;
        }
    }

    foreach ([
        'Stop-Process',
        'Stop-Service',
        'Disable-ScheduledTask',
        'Unregister-ScheduledTask',
        'Remove-Item',
        'Set-ItemProperty',
        'Remove-ItemProperty',
    ] as $forbidden) {
        if (str_contains($block, $forbidden)) {
            $errors[] = 'runtime audit must stay read-only/safe; forbidden primitive: ' . $forbidden;
        }
    }
    if (preg_match('/\\\\?\$pid=/', $block)) {
        $errors[] = 'runtime audit must not assign PowerShell automatic PID variable';
    }
}

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "fredwin-runtime-audit-action-test: ok\n";
