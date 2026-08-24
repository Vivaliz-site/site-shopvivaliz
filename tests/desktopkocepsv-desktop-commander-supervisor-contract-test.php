<?php
$root = dirname(__DIR__);
$files = [
    $root . '/scripts/desktopkocepsv-desktop-commander-runner.ps1',
    $root . '/scripts/desktopkocepsv-desktop-commander-supervisor.ps1',
    $root . '/scripts/desktopkocepsv-desktop-commander-status.ps1'
];
foreach ($files as $p) {
    if (!is_file($p)) { fwrite(STDERR, "FALHOU: ausente {$p}\n"); exit(1); }
}
$all = implode("\n", array_map('file_get_contents', $files));
foreach ([
    'ShopVivaliz DESKTOP-KOCEPSV Desktop Commander 24h',
    '@wonderwhy-er/desktop-commander@0.2.47',
    'remote --persist-session',
    'New-ScheduledTaskTrigger -AtStartup',
    'LogonType S4U',
    'RunLevel Highest',
    'MultipleInstances IgnoreNew',
    'StartWhenAvailable',
    'New-TimeSpan -Minutes 1',
    'Get-CanonicalRemoteLaunchers',
    'Get-NonCanonicalRemoteLaunchers',
    'desktop-commander-remote.vbs',
    'AUTH_REQUIRED',
    'CANONICAL_AGENT_COUNT',
    'NONCANONICAL_AGENT_COUNT'
] as $needle) {
    if (stripos($all, $needle) === false) { fwrite(STDERR, "FALHOU: ausente {$needle}\n"); exit(1); }
}
foreach (['access_token','refresh_token','auth_token','Password=','Get-Process node | Stop-Process','StrictHostKeyChecking=no'] as $needle) {
    if (stripos($all, $needle) !== false) { fwrite(STDERR, "FALHOU: padrao proibido {$needle}\n"); exit(1); }
}
echo "desktopkocepsv-desktop-commander-supervisor-contract: ok\n";
