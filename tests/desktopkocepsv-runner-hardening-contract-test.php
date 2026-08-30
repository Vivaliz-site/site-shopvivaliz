<?php
$root = dirname(__DIR__);
$runner = file_get_contents($root . '/scripts/desktopkocepsv-desktop-commander-runner.ps1');
$supervisor = file_get_contents($root . '/scripts/desktopkocepsv-desktop-commander-supervisor.ps1');
$status = file_get_contents($root . '/scripts/desktopkocepsv-desktop-commander-status.ps1');
if ($runner === false || $supervisor === false || $status === false) {
    fwrite(STDERR, "missing Desktop Commander hardening script\n");
    exit(1);
}
foreach ([
    'ReadLineAsync()',
    'Raw provider output is never persisted',
    'Provider channel recovery timed out',
    'SESSION_REFRESH_PERSISTED=true',
    'PACKAGE_RESOLUTION=verified_hint',
    'PACKAGE_RESOLUTION=npx_then_hint_saved',
    'Remote Desktop Commander runner exited rc=',
] as $needle) {
    if (strpos($runner, $needle) === false) {
        fwrite(STDERR, "runner missing {$needle}\n");
        exit(1);
    }
}
foreach (['shopvivaliz-dc-', 'RedirectStandardOutput $outFile', 'Read-CapturedProviderText'] as $forbidden) {
    if (strpos($runner, $forbidden) !== false) {
        fwrite(STDERR, "runner persists raw capture via {$forbidden}\n");
        exit(1);
    }
}
foreach ([
    'Deploy-OperationalFiles',
    'Set-PrivateAcl',
    '-WakeToRun -Hidden',
    'Remove-LegacyRawCaptures',
    'Microsoft-Windows-TaskScheduler/Operational',
] as $needle) {
    if (strpos($supervisor, $needle) === false) {
        fwrite(STDERR, "supervisor missing {$needle}\n");
        exit(1);
    }
}
foreach (['TASK_ACTION_SECURE=', 'MONITOR_HEALTHY=', 'DEVICE_STATE_ACL_PRIVATE=', 'LEGACY_RAW_CAPTURE_COUNT='] as $needle) {
    if (strpos($status, $needle) === false) {
        fwrite(STDERR, "status missing {$needle}\n");
        exit(1);
    }
}
echo "desktopkocepsv-runner-hardening-contract: ok\n";
