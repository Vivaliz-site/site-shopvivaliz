<?php
$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/fred-win-desktop-commander-action.yml';
if (!is_file($workflowPath)) { fwrite(STDERR, "FALHOU: workflow ausente\n"); exit(1); }
$yml = file_get_contents($workflowPath);
$required = [
    'desktop_commander_status)',
    'desktop_commander_install)',
    'desktop_commander_restart)',
    'desktop_commander_kill_for_recovery_test)',
    'fredwin-desktop-commander-status.ps1',
    'fredwin-desktop-commander-supervisor.ps1',
    "Set-Location 'C:\\site-shopvivaliz'",
    'git fetch origin main',
    'git merge --ff-only origin/main',
    'Action not allowlisted'
];
foreach ($required as $needle) {
    if (strpos($yml, $needle) === false) { fwrite(STDERR, "FALHOU: ausente {$needle}\n"); exit(1); }
}
$forbidden = ['device.json | Get-Content', 'access_token', 'refresh_token', 'auth_token', 'git reset --hard', 'git clean -'];
foreach ($forbidden as $needle) {
    if (stripos($yml, $needle) !== false) { fwrite(STDERR, "FALHOU: segredo/log ou mutacao proibida {$needle}\n"); exit(1); }
}
echo "fredwin-desktop-commander-relay-contract: ok\n";
