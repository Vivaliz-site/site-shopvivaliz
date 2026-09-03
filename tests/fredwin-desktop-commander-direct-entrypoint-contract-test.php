<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$status = (string) file_get_contents($root . '/scripts/fredwin-desktop-commander-status.ps1');

$needles = [
    "-in @('node.exe','cmd.exe')",
    '@wonderwhy-er[\\\\/]desktop-commander[\\\\/]dist[\\\\/]index\\.js',
    "--persist-session",
];

foreach ($needles as $needle) {
    if (strpos($status, $needle) === false) {
        fwrite(STDERR, "fredwin status missing direct-entrypoint detector: {$needle}\n");
        exit(1);
    }
}

echo "fredwin-desktop-commander-direct-entrypoint-contract: ok\n";
