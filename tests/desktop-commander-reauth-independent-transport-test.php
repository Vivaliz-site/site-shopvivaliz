<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$linux = (string) file_get_contents($root . '/scripts/desktop-commander-reauth-linux-once.sh');
$windows = (string) file_get_contents($root . '/scripts/desktop-commander-reauth-windows.ps1');
$workflow = (string) file_get_contents($root . '/.github/workflows/desktop-commander-reauth-runner.yml');

$requiredLinux = [
    'rm -f "$DEVICE_DIR/device.json"',
    'timeout 600s',
    'reauth-session.log',
];
foreach ($requiredLinux as $needle) {
    if (!str_contains($linux, $needle)) {
        fwrite(STDERR, "linux reauth missing independent bounded auth behavior: {$needle}\n");
        exit(1);
    }
}
if (str_contains($linux, 'remote --logout')) {
    fwrite(STDERR, "linux reauth must not invoke remote --logout through npx\n");
    exit(1);
}

$requiredWindows = [
    "Remove-Item -LiteralPath $DeviceFile",
    'timeout.exe /t',
    'dc-reauth-session.log',
];
foreach ($requiredWindows as $needle) {
    if (!str_contains($windows, $needle)) {
        fwrite(STDERR, "windows reauth missing fresh-session behavior: {$needle}\n");
        exit(1);
    }
}
if (str_contains($windows, 'remote --logout')) {
    fwrite(STDERR, "windows reauth must not invoke remote --logout through npx\n");
    exit(1);
}

$workflowRequired = [
    'tailscale status --json',
    'LAPTOP-NIG4IFUU',
    'DESKTOP-KOCEPSV',
    'FRED',
    'user',
    'SSH22=reachable',
];
foreach ($workflowRequired as $needle) {
    if (!str_contains($workflow, $needle)) {
        fwrite(STDERR, "reauth workflow missing private SSH transport contract: {$needle}\n");
        exit(1);
    }
}
if (str_contains($workflow, '/mcp/tool/execute_command')) {
    fwrite(STDERR, "Windows reauth must not execute through the Desktop Commander relay it is stopping\n");
    exit(1);
}

echo "desktop-commander-reauth-independent-transport: ok\n";
