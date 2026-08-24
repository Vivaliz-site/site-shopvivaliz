<?php
$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/fred-win-desktop-commander-action.yml';
$tunnelPath = $root . '/scripts/ssh-tunnel-service-managed.ps1';
$bootstrapPath = $root . '/scripts/fredwin-remote-bootstrap.ps1';
foreach ([$workflowPath, $tunnelPath, $bootstrapPath] as $path) {
    if (!is_file($path)) { fwrite(STDERR, "FALHOU: arquivo ausente {$path}\n"); exit(1); }
}
$yml = file_get_contents($workflowPath);
$tunnel = file_get_contents($tunnelPath);
$bootstrap = file_get_contents($bootstrapPath);
$requiredWorkflow = [
    'desktop_commander_status)', 'desktop_commander_install)',
    'desktop_commander_restart)', 'desktop_commander_kill_for_recovery_test)',
    'fredwin-desktop-commander-status.ps1', 'fredwin-desktop-commander-supervisor.ps1',
    "Set-Location 'C:\\site-shopvivaliz'", 'git fetch origin main',
    'git merge --ff-only origin/main', 'Action not allowlisted'
];
foreach ($requiredWorkflow as $needle) {
    if (strpos($yml, $needle) === false) { fwrite(STDERR, "FALHOU: workflow sem {$needle}\n"); exit(1); }
}
foreach (['-R 5557:127.0.0.1:5557','StrictHostKeyChecking=yes','UserKnownHostsFile=','BatchMode=yes','ExitOnForwardFailure=yes'] as $needle) {
    if (stripos($tunnel, $needle) === false) { fwrite(STDERR, "FALHOU: tunnel sem {$needle}\n"); exit(1); }
}
foreach (['ShopVivaliz FredWin Relay 24h','New-ScheduledTaskTrigger -AtStartup','LogonType S4U','RunLevel Highest','MultipleInstances IgnoreNew','New-TimeSpan -Minutes 1'] as $needle) {
    if (stripos($bootstrap, $needle) === false) { fwrite(STDERR, "FALHOU: bootstrap sem {$needle}\n"); exit(1); }
}
foreach (['StrictHostKeyChecking=accept-new','StrictHostKeyChecking=no','trycloudflare','cloudflare'] as $needle) {
    if (stripos($tunnel . $bootstrap, $needle) !== false) { fwrite(STDERR, "FALHOU: relay local contem padrao proibido {$needle}\n"); exit(1); }
}
foreach (['device.json | Get-Content','access_token','refresh_token','auth_token','git reset --hard','git clean -'] as $needle) {
    if (stripos($yml . $tunnel . $bootstrap, $needle) !== false) { fwrite(STDERR, "FALHOU: segredo ou mutacao proibida {$needle}\n"); exit(1); }
}
echo "fredwin-desktop-commander-relay-contract: ok\n";
