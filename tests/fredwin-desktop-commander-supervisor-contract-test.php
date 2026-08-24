<?php
$root = dirname(__DIR__);
$scriptPath = $root . '/scripts/fredwin-desktop-commander-supervisor.ps1';
$statusPath = $root . '/scripts/fredwin-desktop-commander-status.ps1';
$runnerPath = $root . '/scripts/fredwin-desktop-commander-runner.ps1';
foreach ([$scriptPath, $statusPath, $runnerPath] as $p) {
    if (!is_file($p)) { fwrite(STDERR, "FALHOU: ausente {$p}\n"); exit(1); }
}
$s = file_get_contents($scriptPath);
$st = file_get_contents($statusPath);
$r = file_get_contents($runnerPath);
$required = [
    'Get-DesktopCommanderRemoteLaunchers',
    'Get-CanonicalRemoteLaunchers',
    'Get-NonCanonicalRemoteLaunchers',
    '@wonderwhy-er/desktop-commander@0.2.47',
    '--persist-session',
    'DesktopCommanderHidden',
    'DesktopCommanderUser24x7',
    'desktop-commander.vbs',
    'ShopVivaliz Desktop Commander 24h',
    'New-ScheduledTaskTrigger -AtStartup',
    'LogonType S4U', 'RunLevel Highest',
    'MultipleInstances IgnoreNew', 'StartWhenAvailable',
    'RestartCount', 'RestartInterval', 'New-TimeSpan -Minutes 1',
    'AUTH_REQUIRED', 'WindowStyle Hidden'
];
foreach ($required as $needle) {
    if (stripos($s, $needle) === false) { fwrite(STDERR, "FALHOU: supervisor sem {$needle}\n"); exit(1); }
}
foreach (['CANONICAL_AGENT_COUNT','NONCANONICAL_AGENT_COUNT','TASK_LOGON_TYPE','TASK_RUN_LEVEL','AUTH_REQUIRED'] as $needle) {
    if (stripos($st, $needle) === false) { fwrite(STDERR, "FALHOU: status sem {$needle}\n"); exit(1); }
}
foreach (['@wonderwhy-er/desktop-commander@0.2.47','remote','--persist-session','Please complete authentication','Starting device authorization flow','device code','AUTH_REQUIRED'] as $needle) {
    if (stripos($r, $needle) === false) { fwrite(STDERR, "FALHOU: runner sem {$needle}\n"); exit(1); }
}
$all = $s . $st . $r;
$forbidden = ['access_token','refresh_token','auth_token','ConvertTo-SecureString','Password=','*>>','RedirectStandardOutput','RedirectStandardError','Get-Process node | Stop-Process'];
foreach ($forbidden as $needle) {
    if (stripos($all, $needle) !== false) { fwrite(STDERR, "FALHOU: segredo/log bruto proibido {$needle}\n"); exit(1); }
}
echo "fredwin-desktop-commander-supervisor-contract: ok\n";
