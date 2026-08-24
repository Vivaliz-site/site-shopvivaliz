<?php
$root = dirname(__DIR__);
$scriptPath = $root . '/scripts/fredwin-desktop-commander-supervisor.ps1';
$runnerPath = $root . '/scripts/fredwin-desktop-commander-runner.ps1';
$statusPath = $root . '/scripts/fredwin-desktop-commander-status.ps1';
foreach ([$scriptPath, $runnerPath, $statusPath] as $path) {
    if (!is_file($path)) { fwrite(STDERR, "FALHOU: arquivo ausente {$path}\n"); exit(1); }
}
$s = file_get_contents($scriptPath);
$r = file_get_contents($runnerPath);
$status = file_get_contents($statusPath);
$requiredSupervisor = [
    '.desktop-commander-device', 'device.json', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA',
    'ShopVivaliz Desktop Commander 24h', 'fredwin-desktop-commander-runner.ps1',
    'New-ScheduledTaskTrigger -AtStartup', 'LogonType S4U', 'RunLevel Highest',
    'MultipleInstances IgnoreNew', 'StartWhenAvailable', 'RestartCount', 'RestartInterval',
    'New-TimeSpan -Minutes 1', 'AUTH_REQUIRED', 'WindowStyle Hidden',
    'Get-DesktopCommanderRemoteLaunchers', 'Get-CanonicalRemoteLaunchers',
    'DesktopCommanderHidden', 'DesktopCommanderUser24x7', 'desktop-commander.vbs'
];
foreach ($requiredSupervisor as $needle) {
    if (stripos($s, $needle) === false) { fwrite(STDERR, "FALHOU: supervisor sem {$needle}\n"); exit(1); }
}
foreach (['@wonderwhy-er/desktop-commander@0.2.47','remote','--persist-session','Please complete authentication','Starting device authorization flow','device code','AUTH_REQUIRED'] as $needle) {
    if (stripos($r, $needle) === false) { fwrite(STDERR, "FALHOU: runner sem {$needle}\n"); exit(1); }
}
foreach (['CANONICAL_AGENT_COUNT','NONCANONICAL_AGENT_COUNT','TASK_LOGON_TYPE','TASK_RUN_LEVEL','AUTH_REQUIRED'] as $needle) {
    if (stripos($status, $needle) === false) { fwrite(STDERR, "FALHOU: status sem {$needle}\n"); exit(1); }
}
if (stripos($s, "desktop-commander.*remote' })") !== false && stripos($s, 'Get-CanonicalRemoteLaunchers') === false) {
    fwrite(STDERR, "FALHOU: supervisor ainda aceita processo remoto generico como canonico\n");
    exit(1);
}
$all = $s . $r . $status;
$forbidden = ['access_token','refresh_token','auth_token','ConvertTo-SecureString','Password=','*>>','RedirectStandardOutput','RedirectStandardError','Get-Content -LiteralPath $DeviceFile'];
foreach ($forbidden as $needle) {
    if (stripos($all, $needle) !== false) { fwrite(STDERR, "FALHOU: segredo/log bruto proibido {$needle}\n"); exit(1); }
}
echo "fredwin-desktop-commander-supervisor-contract: ok\n";
