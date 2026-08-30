<?php
$root = dirname(__DIR__);
$runnerPath = $root . '/scripts/desktopkocepsv-desktop-commander-runner.ps1';
$files = [
    $runnerPath,
    $root . '/scripts/desktopkocepsv-desktop-commander-supervisor.ps1',
    $root . '/scripts/desktopkocepsv-desktop-commander-status.ps1'
];
foreach ($files as $p) {
    if (!is_file($p)) { fwrite(STDERR, "FALHOU: ausente {$p}\n"); exit(1); }
}
$all = implode("\n", array_map('file_get_contents', $files));
$runner = (string) file_get_contents($runnerPath);
foreach ([
    'ShopVivaliz DESKTOP-KOCEPSV Desktop Commander 24h',
    '@wonderwhy-er/desktop-commander@0.2.47',
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
    'NONCANONICAL_AGENT_COUNT',
    'Register-ScheduledTask',
    '-ErrorAction Stop',
    'Stop-LauncherTree([int]$ProcessId)',
    'System.Threading.Mutex', 'WaitOne(0)', 'supervisor_mutex_held', 'ReleaseMutex'
] as $needle) {
    if (stripos($all, $needle) === false) { fwrite(STDERR, "FALHOU: ausente {$needle}\n"); exit(1); }
}
if (
    !preg_match("/ArgumentList\\s+@\\([^)]*'remote'\\s*,\\s*'--persist-session'/i", $runner)
    && !preg_match('/Arguments\\s*=.*remote --persist-session/i', $runner)
) {
    fwrite(STDERR, "FALHOU: runner sem argumentos canonicos remote + --persist-session\n");
    exit(1);
}
foreach (['access_token','refresh_token','auth_token','Password=','Get-Process node | Stop-Process','StrictHostKeyChecking=no','Stop-LauncherTree([int]$Pid)'] as $needle) {
    if (stripos($all, $needle) !== false) { fwrite(STDERR, "FALHOU: padrao proibido {$needle}\n"); exit(1); }
}
echo "desktopkocepsv-desktop-commander-supervisor-contract: ok\n";
