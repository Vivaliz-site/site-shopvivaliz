<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$composer = trim((string) shell_exec('command -v composer 2>/dev/null'));
if ($composer === '') {
    fwrite(STDERR, "composer executable not found\n");
    exit(2);
}

$productionPhp = '8.3.6';
$command = sprintf(
    'cd %s && %s prohibits php %s --locked 2>&1',
    escapeshellarg($root),
    escapeshellarg($composer),
    escapeshellarg($productionPhp)
);
$output = trim((string) shell_exec($command));

if (preg_match('/\brequires php \([^)]*\)/i', $output) === 1) {
    fwrite(STDERR, "composer.lock is not compatible with production PHP {$productionPhp}:\n{$output}\n");
    exit(1);
}

echo "Composer production platform contract passed for PHP {$productionPhp}.\n";
