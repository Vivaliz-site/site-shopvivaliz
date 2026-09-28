<?php
$root = dirname(__DIR__);
$bootstrap = $root . '/scripts/desktopkocepsv-remote-bootstrap.ps1';
$tunnel = $root . '/scripts/desktopkocepsv-ssh-tunnel-service-managed.ps1';
$bridge = $root . '/scripts/desktopkocepsv-remote-control-ssh-bridge.ps1';
foreach ([$bootstrap,$tunnel,$bridge] as $p) {
    if (!is_file($p)) { fwrite(STDERR, "FALHOU: ausente {$p}\n"); exit(1); }
}
$all = file_get_contents($bootstrap) . "\n" . file_get_contents($tunnel) . "\n" . file_get_contents($bridge);
foreach ([
    '127.0.0.1:5557',
    '-R 5558:127.0.0.1:5557',
    '-R 2223:127.0.0.1:22',
    'StrictHostKeyChecking=yes',
    'UserKnownHostsFile=',
    'ExitOnForwardFailure=yes',
    'ServerAliveInterval=30',
    'ServerAliveCountMax=3',
    'ShopVivaliz DESKTOP-KOCEPSV Relay 24h',
    'New-ScheduledTaskTrigger -AtStartup',
    'LogonType S4U',
    'RunLevel Highest',
    'function Ensure-Task',
    'Get-ScheduledTask -TaskName $TaskName',
    'if (-not $task) {',
    'Install-Task',
    'Enable-ScheduledTask -TaskName $TaskName',
    'Ensure-Task',
    '*-R*2223:127.0.0.1:22*',
    'ShopVivaliz DESKTOP-KOCEPSV Remote Control SSH 24h',
    'REMOTE_CONTROL_KOCEPSV_SIDECAR_TASK=PASS',
    'REMOTE_CONTROL_KOCEPSV_SIDECAR=PASS',
    'Start-RemoteControlTunnel',
    '[regex]::Replace',
    'function Capture-WorkingTunnelConfig',
    'desktopkocepsv-relay-runtime.json',
    'Loaded persisted connection metadata captured from working legacy tunnel',
    'backend_host',
    'known_hosts_path',
    'key_path'
] as $needle) {
    if (stripos($all, $needle) === false) { fwrite(STDERR, "FALHOU: relay sem {$needle}\n"); exit(1); }
}
foreach (['StrictHostKeyChecking=no','StrictHostKeyChecking=accept-new','0.0.0.0:5557','-R 0.0.0.0:5558','-R 0.0.0.0:2223'] as $needle) {
    if (stripos($all, $needle) !== false) { fwrite(STDERR, "FALHOU: relay inseguro {$needle}\n"); exit(1); }
}
echo "desktopkocepsv-private-relay-contract: ok\n";
