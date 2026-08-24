<?php
$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/fred-win-desktop-commander-action.yml';
$bootstrapPath = $root . '/scripts/fredwin-remote-bootstrap.ps1';
$tunnelPath = $root . '/scripts/ssh-tunnel-service-managed.ps1';
foreach ([$workflowPath,$bootstrapPath,$tunnelPath] as $p) {
    if (!is_file($p)) { fwrite(STDERR, "FALHOU: ausente {$p}\n"); exit(1); }
}
$yml = file_get_contents($workflowPath);
$bootstrap = file_get_contents($bootstrapPath);
$tunnel = file_get_contents($tunnelPath);
foreach ([
    'desktop_commander_status)',
    'desktop_commander_install)',
    'desktop_commander_restart)',
    'desktop_commander_kill_for_recovery_test)',
    'fredwin-desktop-commander-status.ps1',
    'fredwin-desktop-commander-supervisor.ps1',
    'Action not allowlisted'
] as $needle) {
    if (strpos($yml, $needle) === false) { fwrite(STDERR, "FALHOU: workflow sem {$needle}\n"); exit(1); }
}
foreach ([
    '-R 5557:127.0.0.1:5557',
    'StrictHostKeyChecking=yes',
    'UserKnownHostsFile=',
    'ExitOnForwardFailure=yes',
    'ServerAliveInterval=30',
    'ServerAliveCountMax=3'
] as $needle) {
    if (stripos($tunnel, $needle) === false) { fwrite(STDERR, "FALHOU: tunnel sem {$needle}\n"); exit(1); }
}
foreach ([
    'ShopVivaliz Fred-Win Relay 24h',
    'New-ScheduledTaskTrigger -AtStartup',
    'LogonType S4U',
    'RunLevel Highest',
    'New-TimeSpan -Minutes 1'
] as $needle) {
    if (stripos($bootstrap, $needle) === false) { fwrite(STDERR, "FALHOU: bootstrap sem {$needle}\n"); exit(1); }
}
$all = $yml . $bootstrap . $tunnel;
foreach (['access_token','refresh_token','auth_token','StrictHostKeyChecking=no','StrictHostKeyChecking=accept-new','git reset --hard','git clean -'] as $needle) {
    if (stripos($all, $needle) !== false) { fwrite(STDERR, "FALHOU: padrao proibido {$needle}\n"); exit(1); }
}
echo "fredwin-desktop-commander-relay-contract: ok\n";
