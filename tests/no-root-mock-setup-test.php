<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$forbiddenFiles = [
    $root . '/setup-auto.sh',
];

foreach ($forbiddenFiles as $path) {
    if (is_file($path)) {
        fwrite(STDERR, "Forbidden mock-oriented root installer exists: {$path}\n");
        exit(1);
    }
}

$rootScripts = glob($root . '/*.{sh,ps1}', GLOB_BRACE) ?: [];
$forbiddenPatterns = [
    '/pk_live_default/i',
    '/mock\s+api/i',
    '/simulad[oa]/i',
];

foreach ($rootScripts as $script) {
    $source = file_get_contents($script);
    if (!is_string($source)) {
        continue;
    }
    foreach ($forbiddenPatterns as $pattern) {
        if (preg_match($pattern, $source) === 1) {
            fwrite(STDERR, "Mock or placeholder setup pattern found in root script: {$script}\n");
            exit(1);
        }
    }
}

echo "OK: no mock-oriented root setup scripts\n";
