<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$forbiddenPaths = [
    $root . '/setup-auto.sh',
    $root . '/claude/medusa',
    $root . '/medusa',
];

foreach ($forbiddenPaths as $path) {
    if (file_exists($path)) {
        fwrite(STDERR, "Forbidden cancelled or mock component exists: {$path}\n");
        exit(1);
    }
}

$rootScripts = glob($root . '/*.{sh,ps1}', GLOB_BRACE) ?: [];
$forbiddenPatterns = [
    '/pk_live_default/i',
    '/mock\s+api/i',
    '/simulad[oa]/i',
    '/claude\/medusa/i',
    '/\bmedusa\b/i',
];

foreach ($rootScripts as $script) {
    $source = file_get_contents($script);
    if (!is_string($source)) {
        continue;
    }
    foreach ($forbiddenPatterns as $pattern) {
        if (preg_match($pattern, $source) === 1) {
            fwrite(STDERR, "Cancelled, mock or placeholder setup pattern found in root script: {$script}\n");
            exit(1);
        }
    }
}

echo "OK: no cancelled Medusa or mock-oriented root setup\n";
