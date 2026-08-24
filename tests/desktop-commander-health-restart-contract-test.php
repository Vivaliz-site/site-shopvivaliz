<?php
$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/desktop-commander-24h-health.yml';
if (!is_file($workflowPath)) { fwrite(STDERR, "FALHOU: health workflow ausente\n"); exit(1); }
$yml = file_get_contents($workflowPath);
$required = [
    "schedule:",
    "*/5 * * * *",
    "workflow_dispatch:",
    "contents: read",
    "issues: write",
    "contract:",
    "runtime:",
    "php tests/desktop-commander-persist-session-contract-test.php",
    "php tests/desktop-commander-health-restart-contract-test.php",
    "php tests/desktop-commander-three-host-action-contract-test.php",
    "SHOPVIVALIZ_VM_KNOWN_HOSTS",
    "StrictHostKeyChecking=yes",
    "UserKnownHostsFile=",
    "LAPTOP-NIG4IFUU",
    "DESKTOP-KOCEPSV",
    "shopvivaliz-ai",
    "5557",
    "5558",
    "fredwin-desktop-commander-status.ps1",
    "desktopkocepsv-desktop-commander-status.ps1",
    "shopvivaliz-desktop-commander.service",
    "MAX_REPAIR_ATTEMPTS = 1",
    "Desktop Commander 24h Control Plane Status"
];
foreach ($required as $needle) {
    if (strpos($yml, $needle) === false) {
        fwrite(STDERR, "FALHOU: health workflow sem {$needle}\n");
        exit(1);
    }
}
$forbidden = [
    'StrictHostKeyChecking=no',
    'authorize)',
    'read_auth)',
    'access_token',
    'refresh_token',
    'auth_token',
    'device code',
    'verification_uri',
    'contents: write',
    'git push'
];
foreach ($forbidden as $needle) {
    if (stripos($yml, $needle) !== false) {
        fwrite(STDERR, "FALHOU: health workflow contem padrao proibido {$needle}\n");
        exit(1);
    }
}
echo "desktop-commander-health-restart-contract: ok\n";
