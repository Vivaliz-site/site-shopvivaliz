<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$forbidden = [
    'test-real-purchase-boleto.php',
    'teste-produto2.php',
];

foreach ($forbidden as $relative) {
    $path = $root . '/' . $relative;
    if (is_file($path)) {
        fwrite(STDERR, "Public root test endpoint must not exist: {$relative}\n");
        exit(1);
    }
}

$rootPhp = glob($root . '/{test,teste}-*.php', GLOB_BRACE) ?: [];
foreach ($rootPhp as $path) {
    $relative = basename($path);
    fwrite(STDERR, "Executable test PHP file is exposed at repository root: {$relative}\n");
    exit(1);
}

$rootPhp = glob($root . '/teste*.php') ?: [];
foreach ($rootPhp as $path) {
    $relative = basename($path);
    fwrite(STDERR, "Executable test PHP file is exposed at repository root: {$relative}\n");
    exit(1);
}

echo "OK: no public PHP test endpoints at repository root\n";
