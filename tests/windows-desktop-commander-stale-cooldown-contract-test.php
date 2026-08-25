<?php
$root = dirname(__DIR__);
$files = [
    'scripts/fredwin-desktop-commander-status.ps1',
    'scripts/fredwin-desktop-commander-supervisor.ps1',
    'scripts/desktopkocepsv-desktop-commander-status.ps1',
    'scripts/desktopkocepsv-desktop-commander-supervisor.ps1',
];
foreach ($files as $file) {
    $text = file_get_contents($root . '/' . $file);
    foreach (['DeviceFile', 'CooldownFile', 'LastWriteTimeUtc'] as $needle) {
        if (stripos($text, $needle) === false) {
            fwrite(STDERR, "windows-dc-stale-cooldown: {$file} missing {$needle}\n");
            exit(1);
        }
    }
    if (stripos($text, 'DeviceStateNewerThanCooldown') === false) {
        fwrite(STDERR, "windows-dc-stale-cooldown: {$file} missing DeviceStateNewerThanCooldown\n");
        exit(1);
    }
}
echo "windows-desktop-commander-stale-cooldown: ok\n";
