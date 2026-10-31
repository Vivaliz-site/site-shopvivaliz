<?php
declare(strict_types=1);

$root = $argv[1] ?? dirname(__DIR__);
$scriptPath = $root . '/scripts/fredwin-rdc-fred-only-guardian.ps1';
$policyPath = $root . '/docs/knowledge/agent-rules.md';

foreach ([$scriptPath, $policyPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "MISSING: {$path}\n");
        exit(1);
    }
}

$script = (string)file_get_contents($scriptPath);
$policy = (string)file_get_contents($policyPath);

foreach ([
    'fred.discouto@hotmail.com',
    'Assert-WindowsIdentity',
    'Assert-LegacyTasksDisabled',
    'Assert-ApprovedBinding',
    'Get-LocalDeviceId',
    'New-ScheduledTaskTrigger -AtLogOn',
    '-LogonType Interactive',
    '-MultipleInstances IgnoreNew',
    'RDC_FRED_DEVICE_BINDING=VERIFIED',
    '@wonderwhy-er/desktop-commander@0.3.1',
] as $required) {
    if (strpos($script, $required) === false) {
        fwrite(STDERR, "REQUIRED_MARKER_MISSING: {$required}\n");
        exit(1);
    }
}

foreach (['taskkill', 'Stop-Process', 'Enable-ScheduledTask', 'Unregister-ScheduledTask', 'remote --logout'] as $forbidden) {
    if (stripos($script, $forbidden) !== false) {
        fwrite(STDERR, "UNSAFE_MARKER_PRESENT: {$forbidden}\n");
        exit(1);
    }
}

if (strpos($policy, '<!-- RDC_FRED_ONLY_IDENTITY_GATE_20261030 -->') === false) {
    fwrite(STDERR, "POLICY_MISSING\n");
    exit(1);
}

echo "fredwin-rdc-fred-only-contract: ok\n";
