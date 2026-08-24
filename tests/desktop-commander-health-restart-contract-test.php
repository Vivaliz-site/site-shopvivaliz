<?php
$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/desktop-commander-24h-health.yml';
if (!is_file($workflowPath)) { fwrite(STDERR, "FALHOU: health workflow ausente\n"); exit(1); }
$yml = file_get_contents($workflowPath);
$required = [
    "push:",
    "branches: [main]",
    "contract:",
    "runtime:",
    "if: github.event_name != 'pull_request'",
    "php tests/desktop-commander-persist-session-contract-test.php",
    "php tests/desktop-commander-health-restart-contract-test.php",
    "desktop_commander_restart",
    "fredwin-desktop-commander-supervisor.ps1",
    "-Mode Restart",
    "Restart VM supervisor when requested",
    "ops/vm-desktop-commander-request.json",
    "systemctl restart shopvivaliz-desktop-commander.service",
    "Verify Fred-Win supervisor through private relay",
    "status_command =",
    "AUTH_REQUIRED"
];
foreach ($required as $needle) {
    if (strpos($yml, $needle) === false) {
        fwrite(STDERR, "FALHOU: health workflow sem {$needle}\n");
        exit(1);
    }
}
$forbidden = ['access_token', 'refresh_token', 'auth_token', 'device code', 'exit' . ' 0'];
foreach ($forbidden as $needle) {
    if (stripos($yml, $needle) !== false) {
        fwrite(STDERR, "FALHOU: health workflow contem padrao proibido {$needle}\n");
        exit(1);
    }
}
echo "desktop-commander-health-restart-contract: ok\n";
