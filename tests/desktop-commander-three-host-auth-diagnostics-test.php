<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$ps = (string) file_get_contents($root . '/scripts/desktop-commander-reauth-windows.ps1');
$workflow = (string) file_get_contents($root . '/.github/workflows/dc-four-host-login-once.yml');

$requiredPs = [
    "'Verify this device in your browser:\\s*(https://\\S+)'",
    "'Please visit:\\s*(https://\\S+)'",
    "+ '\\[[0-?]*[ -/]*[@-~]'",
];
foreach ($requiredPs as $needle) {
    if (!str_contains($ps, $needle)) {
        fwrite(STDERR, "Windows DC parser missing single-backslash regex: {$needle}\n");
        exit(1);
    }
}
if (str_contains($ps, "'Verify this device in your browser:\\\\s*")) {
    fwrite(STDERR, "Windows DC parser still contains double-backslash whitespace regex\n");
    exit(1);
}

$requiredWorkflow = [
    "if: steps.generate.outputs.missing_hosts != ''",
    'Diagnose Windows Desktop Commander auth failure safely',
    'DC_WINDOWS_AUTH_DIAG',
    'desktop-commander-reauth-windows.ps1',
    'verification_url_prompt',
    'waiting_authorization',
    'device_ready',
];
foreach ($requiredWorkflow as $needle) {
    if (!str_contains($workflow, $needle)) {
        fwrite(STDERR, "missing post-partial DC diagnostic contract: {$needle}\n");
        exit(1);
    }
}

echo "desktop-commander-three-host-auth-diagnostics: ok\n";
