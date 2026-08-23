<?php
$root = dirname(__DIR__);
$scriptPath = $root . '/scripts/fredwin-desktop-commander-supervisor.ps1';
if (!is_file($scriptPath)) { fwrite(STDERR, "FALHOU: supervisor ausente\n"); exit(1); }
$s = file_get_contents($scriptPath);
$required = [
    '.desktop-commander-device',
    'device.json',
    'USERPROFILE',
    'APPDATA',
    'LOCALAPPDATA',
    '@wonderwhy-er/desktop-commander@0.2.47',
    ' remote',
    'ShopVivaliz Desktop Commander 24h',
    'New-ScheduledTaskTrigger -AtStartup',
    'LogonType S4U',
    'RunLevel Highest',
    'MultipleInstances IgnoreNew',
    'StartWhenAvailable',
    'RestartCount',
    'RestartInterval',
    'AUTH_REQUIRED',
    'WindowStyle Hidden'
];
foreach ($required as $needle) {
    if (stripos($s, $needle) === false) { fwrite(STDERR, "FALHOU: ausente {$needle}\n"); exit(1); }
}
$forbidden = ['access_token', 'refresh_token', 'auth_token', 'ConvertTo-SecureString', 'Password='];
foreach ($forbidden as $needle) {
    if (stripos($s, $needle) !== false) { fwrite(STDERR, "FALHOU: segredo/credencial proibido {$needle}\n"); exit(1); }
}
echo "fredwin-desktop-commander-supervisor-contract: ok\n";
