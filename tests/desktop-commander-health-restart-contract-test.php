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
    "actions/upload-artifact@v4",
    "if-no-files-found: error",
    "contract:",
    "runtime:",
    "php tests/desktop-commander-persist-session-contract-test.php",
    "php tests/desktop-commander-health-restart-contract-test.php",
    "php tests/fredwin-desktop-commander-stale-transport-contract-test.php",
    "php tests/vm-desktop-commander-auth-classification-contract-test.php",
    "php tests/fredwin-desktop-commander-duplicate-owner-contract-test.php",
    "php tests/windows-desktop-commander-pre-mutex-fastpath-contract-test.php",
    "php tests/desktopkocepsv-duplicate-owner-contract-test.php",
    "php tests/local-auto-sync-dc-separation-contract-test.php",
    "php tests/vm-desktop-commander-guardian-contract-test.php",
    "php tests/desktop-commander-four-host-monitor-contract-test.php",
    "shopvivaliz-free-a1",
    "shopvivaliz-free-a1-monitor",
    "Sanitized four-host health only.",
    "php tests/desktop-commander-three-host-action-contract-test.php",
    "SHOPVIVALIZ_VM_KNOWN_HOSTS",
    "StrictHostKeyChecking=yes",
    "UserKnownHostsFile=",
    "LAPTOP-NIG4IFUU",
    "DESKTOP-KOCEPSV",
    "shopvivaliz-a1-backend",
    "5557",
    "5558",
    "fredwin-desktop-commander-status.ps1",
    "desktopkocepsv-desktop-commander-status.ps1",
    "shopvivaliz-desktop-commander.service",
    "ALLOW_REPAIR: \${{ github.event_name == 'workflow_dispatch' && '1' || '0' }}",
    "MAX_REPAIR_ATTEMPTS = 1 if os.environ.get('ALLOW_REPAIR') == '1' else 0",
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
    'issues: write',
    'gh issue',
    'git push'
];
foreach ($forbidden as $needle) {
    if (stripos($yml, $needle) !== false) {
        fwrite(STDERR, "FALHOU: health workflow contem padrao proibido {$needle}\n");
        exit(1);
    }
}
echo "desktop-commander-health-restart-contract: ok\n";
