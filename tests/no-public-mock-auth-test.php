<?php
declare(strict_types=1);

$forbiddenPaths = [
    __DIR__ . '/../auth/google-mock-login.php',
];

foreach ($forbiddenPaths as $path) {
    if (is_file($path)) {
        fwrite(STDERR, "FAIL: public mock authentication surface exists: {$path}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/auth', FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $source = file_get_contents($file->getPathname());
    if (!is_string($source)) {
        fwrite(STDERR, "FAIL: could not read {$file->getPathname()}\n");
        exit(1);
    }

    $dangerousSignals = [
        "provider_id' => 'mock-",
        "\$_SESSION['user_id'] = 9999",
        "\$_SESSION['is_admin'] = 1",
    ];

    foreach ($dangerousSignals as $signal) {
        if (str_contains($source, $signal)) {
            fwrite(STDERR, "FAIL: simulated authentication signal found in {$file->getPathname()}\n");
            exit(1);
        }
    }
}

echo "no-public-mock-auth: ok\n";
