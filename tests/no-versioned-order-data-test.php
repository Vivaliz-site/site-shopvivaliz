<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$ordersDir = $root . '/storage/orders';

if (is_dir($ordersDir)) {
    $entries = array_values(array_filter(
        scandir($ordersDir) ?: [],
        static fn(string $entry): bool => $entry !== '.' && $entry !== '..' && $entry !== '.gitkeep'
    ));
    if ($entries !== []) {
        fwrite(STDERR, "Operational order files must not be versioned: " . implode(', ', $entries) . PHP_EOL);
        exit(1);
    }
}

$gitignore = file_get_contents($root . '/.gitignore');
if (!is_string($gitignore) || preg_match('~^storage/orders/$~m', $gitignore) !== 1) {
    fwrite(STDERR, "storage/orders/ must remain ignored." . PHP_EOL);
    exit(1);
}

echo "OK: no operational order data is versioned" . PHP_EOL;
