<?php
$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/fred-win-desktop-commander-action.yml';
if (!is_file($workflowPath)) { fwrite(STDERR, "FALHOU: workflow ausente\n"); exit(1); }
$yml = (string) file_get_contents($workflowPath);
$required = [
    'status)',
    'install_or_repair)',
    'recover_session)',
    'restart)',
    'kill_for_recovery_test)',
    'fredwin-desktop-commander-runner.ps1',
    'fredwin-desktop-commander-status.ps1',
    'fredwin-desktop-commander-supervisor.ps1',
    'patch-desktop-commander-session-persistence.mjs',
    "Set-Location 'C:\\site-shopvivaliz'",
    'git fetch origin main',
    'git restore --source=origin/main --',
    'Action not allowlisted'
];
foreach ($required as $needle) {
    if (strpos($yml, $needle) === false) { fwrite(STDERR, "FALHOU: ausente {$needle}\n"); exit(1); }
}
$forbidden = [
    'device.json | Get-Content', 'access_token', 'refresh_token', 'auth_token',
    'git reset --hard', 'git clean -', 'git merge --ff-only origin/main',
    'ops/windows-task/FredWin-DesktopCommander-24h.xml'
];
foreach ($forbidden as $needle) {
    if (stripos($yml, $needle) !== false) { fwrite(STDERR, "FALHOU: segredo/log, artefato aposentado ou mutacao proibida {$needle}\n"); exit(1); }
}
echo "fredwin-desktop-commander-relay-contract: ok\n";
