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
            $errors[] = 'runtime audit must stay read-only; forbidden primitive: ' . $forbidden;
        }
    }
}

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "fredwin-runtime-audit-action-test: ok\n";
