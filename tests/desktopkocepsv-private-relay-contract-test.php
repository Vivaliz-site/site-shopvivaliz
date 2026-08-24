<?php
$root = dirname(__DIR__);
$bootstrap = $root . '/scripts/desktopkocepsv-remote-bootstrap.ps1';
$tunnel = $root . '/scripts/desktopkocepsv-ssh-tunnel-service-managed.ps1';
foreach ([$bootstrap,$tunnel] as $p) {
    if (!is_file($p)) { fwrite(STDERR, "FALHOU: ausente {$p}\n"); exit(1); }
}
$all = file_get_contents($bootstrap) . "\n" . file_get_contents($tunnel);
foreach ([
    '127.0.0.1:5557',
    '-R 5558:127.0.0.1:5557',
    'StrictHostKeyChecking=yes',
    'UserKnownHostsFile=',
    'ExitOnForwardFailure=yes',
    'ServerAliveInterval=30',
    'ServerAliveCountMax=3',
    'ShopVivaliz DESKTOP-KOCEPSV Relay 24h',
    'New-ScheduledTaskTrigger -AtStartup',
    'LogonType S4U',
    'RunLevel Highest'
] as $needle) {
    if (stripos($all, $needle) === false) { fwrite(STDERR, "FALHOU: relay sem {$needle}\n"); exit(1); }
}
foreach (['StrictHostKeyChecking=no','StrictHostKeyChecking=accept-new','0.0.0.0:5557','-R 0.0.0.0:5558'] as $needle) {
    if (stripos($all, $needle) !== false) { fwrite(STDERR, "FALHOU: relay inseguro {$needle}\n"); exit(1); }
}
echo "desktopkocepsv-private-relay-contract: ok\n";
