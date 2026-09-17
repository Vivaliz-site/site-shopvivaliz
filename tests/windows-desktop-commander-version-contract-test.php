<?php
$root = dirname(__DIR__);
$files = [
    'scripts/fredwin-desktop-commander-runner.ps1',
    'scripts/fredwin-desktop-commander-supervisor.ps1',
    'scripts/fredwin-desktop-commander-status.ps1',
    'scripts/desktopkocepsv-desktop-commander-runner.ps1',
    'scripts/desktopkocepsv-desktop-commander-supervisor.ps1',
    'scripts/desktopkocepsv-desktop-commander-status.ps1',
];
foreach ($files as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        fwrite(STDERR, "missing Windows DC surface: {$relative}\n");
        exit(1);
    }
    $text = (string) file_get_contents($path);
    $normalized = str_replace('\\.', '.', $text);
    if (strpos($normalized, '0.2.47') !== false) {
        fwrite(STDERR, "stale Windows DC 0.2.47 pin remains: {$relative}\n");
        exit(1);
    }
    if (strpos($normalized, '0.2.48') === false) {
        fwrite(STDERR, "Windows DC surface not pinned to 0.2.48: {$relative}\n");
        exit(1);
    }
}
echo "windows-desktop-commander-version-contract: ok\n";
