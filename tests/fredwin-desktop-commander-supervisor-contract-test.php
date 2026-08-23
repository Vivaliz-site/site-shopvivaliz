<?php
$root = dirname(__DIR__);
$scriptPath = $root . '/scripts/fredwin-desktop-commander-supervisor.ps1';
$runnerPath = $root . '/scripts/fredwin-desktop-commander-runner.ps1';
if (!is_file($scriptPath)) { fwrite(STDERR, "FALHOU: supervisor ausente\n"); exit(1); }
if (!is_file($runnerPath)) { fwrite(STDERR, "FALHOU: runner sanitizado ausente\n"); exit(1); }
$s = file_get_contents($scriptPath);
$r = file_get_contents($runnerPath);
$required = [
    '.desktop-commander-device', 'device.json', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA',
    'ShopVivaliz Desktop Commander 24h', 'fredwin-desktop-commander-runner.ps1',
    'New-ScheduledTaskTrigger -AtStartup', 'LogonType S4U', 'RunLevel Highest',
    'MultipleInstances IgnoreNew', 'StartWhenAvailable', 'RestartCount', 'RestartInterval',
    'New-TimeSpan -Minutes 1', 'AUTH_REQUIRED', 'WindowStyle Hidden'
];
foreach ($required as $needle) {
    if (stripos($s, $needle) === false) { fwrite(STDERR, "FALHOU: supervisor sem {$needle}\n"); exit(1); }
}
foreach (['@wonderwhy-er/desktop-commander@0.2.47','remote','Please complete authentication','Starting device authorization flow','device code','AUTH_REQUIRED'] as $needle) {
    if (stripos($r, $needle) === false) { fwrite(STDERR, "FALHOU: runner sem {$needle}\n"); exit(1); }
}
$all = $s . $r;
$forbidden = ['access_token','refresh_token','auth_token','ConvertTo-SecureString','Password=','*>>','RedirectStandardOutput','RedirectStandardError'];
foreach ($forbidden as $needle) {
    if (stripos($all, $needle) !== false) { fwrite(STDERR, "FALHOU: segredo/log bruto proibido {$needle}\n"); exit(1); }
}
echo "fredwin-desktop-commander-supervisor-contract: ok\n";
