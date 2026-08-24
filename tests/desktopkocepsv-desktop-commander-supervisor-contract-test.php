<?php
$root = dirname(__DIR__);
$supervisorPath = $root . '/scripts/desktopkocepsv-desktop-commander-supervisor.ps1';
$runnerPath = $root . '/scripts/desktopkocepsv-desktop-commander-runner.ps1';
$statusPath = $root . '/scripts/desktopkocepsv-desktop-commander-status.ps1';
foreach ([$supervisorPath, $runnerPath, $statusPath] as $path) {
    if (!is_file($path)) { fwrite(STDERR, "FALHOU: arquivo ausente {$path}\n"); exit(1); }
}
$s = file_get_contents($supervisorPath);
$r = file_get_contents($runnerPath);
$status = file_get_contents($statusPath);
foreach ([
    'ShopVivaliz DESKTOP-KOCEPSV Desktop Commander 24h',
    'New-ScheduledTaskTrigger -AtStartup',
    'LogonType S4U',
    'RunLevel Highest',
    'MultipleInstances IgnoreNew',
    'StartWhenAvailable',
    'RestartCount',
    'RestartInterval',
    'New-TimeSpan -Minutes 1',
    'Get-DesktopCommanderRemoteLaunchers',
    'Get-CanonicalRemoteLaunchers',
    'desktop-commander-remote.vbs',
    'AUTH_REQUIRED'
] as $needle) {
    if (stripos($s, $needle) === false) { fwrite(STDERR, "FALHOU: supervisor sem {$needle}\n"); exit(1); }
}
foreach (['@wonderwhy-er/desktop-commander@0.2.47','remote','--persist-session','Please complete authentication','Starting device authorization flow','device code','AUTH_REQUIRED'] as $needle) {
    if (stripos($r, $needle) === false) { fwrite(STDERR, "FALHOU: runner sem {$needle}\n"); exit(1); }
}
foreach (['DEVICE_STATE_EXISTS','CANONICAL_AGENT_COUNT','NONCANONICAL_AGENT_COUNT','TASK_LOGON_TYPE','TASK_RUN_LEVEL','AUTH_REQUIRED'] as $needle) {
    if (stripos($status, $needle) === false) { fwrite(STDERR, "FALHOU: status sem {$needle}\n"); exit(1); }
}
$all = $s . $r . $status;
foreach (['access_token','refresh_token','auth_token','Password=','Get-Content -LiteralPath $DeviceFile','RedirectStandardOutput','RedirectStandardError'] as $needle) {
    if (stripos($all, $needle) !== false) { fwrite(STDERR, "FALHOU: segredo/log bruto proibido {$needle}\n"); exit(1); }
}
echo "desktopkocepsv-desktop-commander-supervisor-contract: ok\n";
