<?php
$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/desktop-commander-24h-health.yml';
if (!is_file($workflowPath)) { fwrite(STDERR, "FALHOU: health workflow ausente\n"); exit(1); }
$yml = file_get_contents($workflowPath);
$required = [
    "desktop_commander_restart",
    "fredwin-desktop-commander-supervisor.ps1",
    "-Mode Restart",
    "sleep 15",
    "Verify Fred-Win supervisor through private relay",
    "AUTH_COOLDOWN_EXISTS",
    "status_command =",
    "cooldown_command =",
    "print('FREDWIN_RESTART_DIAGNOSTIC=ok')\n          PY\n            sleep 15"
];
foreach ($required as $needle) {
    if (strpos($yml, $needle) === false) {
        fwrite(STDERR, "FALHOU: health workflow sem {$needle}\n");
        exit(1);
    }
}
$forbidden = ['access_token', 'refresh_token', 'auth_token', 'device code', 'exit 0', "values.get('AUTH_REQUIRED'"];
foreach ($forbidden as $needle) {
    if (stripos($yml, $needle) !== false) {
        fwrite(STDERR, "FALHOU: health workflow contem padrao proibido {$needle}\n");
        exit(1);
    }
}
echo "desktop-commander-health-restart-contract: ok\n";
