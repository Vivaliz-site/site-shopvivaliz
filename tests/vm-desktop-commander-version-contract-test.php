<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$files = [
    'scripts/vm-desktop-commander-supervisor.sh',
    'scripts/install-vm-desktop-commander-service.sh',
    '.github/workflows/desktop-commander-24h-health.yml',
    '.github/workflows/vm-desktop-commander-action.yml',
    'tests/vm-desktop-commander-service-contract-test.php',
];
foreach ($files as $relative) {
    $path = $root . '/' . $relative;
    $text = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($text)) {
        fwrite(STDERR, "missing Linux DC surface: {$relative}\n");
        exit(1);
    }
    if (strpos($text, '0.2.51') === false) {
        fwrite(STDERR, "Linux DC surface not pinned to 0.2.51: {$relative}\n");
        exit(1);
    }
    foreach (['@wonderwhy-er/desktop-commander@0.2.47', '@wonderwhy-er/desktop-commander@0.2.48'] as $stale) {
        if (strpos($text, $stale) !== false) {
            fwrite(STDERR, "stale Linux DC pin remains in {$relative}: {$stale}\n");
            exit(1);
        }
    }
}
echo "vm-desktop-commander-version-contract: ok\n";
