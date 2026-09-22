<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$tunnels = [
    $root . '/scripts/ssh-tunnel-service-managed.ps1',
    $root . '/scripts/desktopkocepsv-ssh-tunnel-service-managed.ps1',
];
foreach ($tunnels as $path) {
    $src = (string) file_get_contents($path);
    foreach (['100.66.174.74', 'C:\\Program Files\\Git\\usr\\bin\\ssh.exe', 'StrictHostKeyChecking=yes', 'ExitOnForwardFailure=yes'] as $needle) {
        if (!str_contains($src, $needle)) { fwrite(STDERR, "relay missing {$needle}: {$path}\n"); exit(1); }
    }
    if (str_contains($src, '137.131.149.55')) { fwrite(STDERR, "public SSH fallback forbidden: {$path}\n"); exit(1); }
}
$bootstraps = [
    $root . '/scripts/fredwin-remote-bootstrap.ps1',
    $root . '/scripts/desktopkocepsv-remote-bootstrap.ps1',
];
foreach ($bootstraps as $path) {
    $src = (string) file_get_contents($path);
    foreach (['Enable-ScheduledTask -TaskName $TaskName', 'failed to stay running'] as $needle) {
        if (!str_contains($src, $needle)) { fwrite(STDERR, "watchdog missing {$needle}: {$path}\n"); exit(1); }
    }
}
echo "windows-desktop-commander-private-relay-persistence: ok\n";
