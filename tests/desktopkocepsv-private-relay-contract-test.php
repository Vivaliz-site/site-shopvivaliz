<?php
$root = dirname(__DIR__);
$bootstrapPath = $root . '/scripts/desktopkocepsv-remote-bootstrap.ps1';
$tunnelPath = $root . '/scripts/desktopkocepsv-ssh-tunnel-service-managed.ps1';
foreach ([$bootstrapPath, $tunnelPath] as $path) {
    if (!is_file($path)) { fwrite(STDERR, "FALHOU: arquivo ausente {$path}\n"); exit(1); }
}
$b = file_get_contents($bootstrapPath);
$t = file_get_contents($tunnelPath);
foreach (['scripts\\mcp-server.py','--port','5557','--env','desktop-kocepsv','127.0.0.1','ShopVivaliz DESKTOP-KOCEPSV Relay 24h','New-ScheduledTaskTrigger -AtStartup','LogonType S4U','RunLevel Highest'] as $needle) {
    if (stripos($b, $needle) === false) { fwrite(STDERR, "FALHOU: bootstrap sem {$needle}\n"); exit(1); }
}
foreach (['-R 5558:127.0.0.1:5557','StrictHostKeyChecking=yes','UserKnownHostsFile=','BatchMode=yes','ExitOnForwardFailure=yes','ServerAliveInterval=30','ServerAliveCountMax=3'] as $needle) {
    if (stripos($t, $needle) === false) { fwrite(STDERR, "FALHOU: tunnel sem {$needle}\n"); exit(1); }
}
foreach (['StrictHostKeyChecking=no','StrictHostKeyChecking=accept-new','trycloudflare','cloudflare'] as $needle) {
    if (stripos($b . $t, $needle) !== false) { fwrite(STDERR, "FALHOU: relay contem padrao proibido {$needle}\n"); exit(1); }
}
echo "desktopkocepsv-private-relay-contract: ok\n";
