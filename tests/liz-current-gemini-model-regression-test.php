<?php
declare(strict_types=1);

function assert_contains(string $path, string $needle): void {
    $src = file_get_contents($path);
    if (!is_string($src) || !str_contains($src, $needle)) {
        fwrite(STDERR, "FAIL: {$path} must default to {$needle}\n");
        exit(1);
    }
}

assert_contains(__DIR__ . '/../api/liz-general.php', "getenv('GEMINI_MODEL') ?: 'gemini-3.1-flash-lite'");
assert_contains(__DIR__ . '/../api/liz-intelligent.php', "liz_env('GEMINI_MODEL') ?: 'gemini-3.1-flash-lite'");

echo "PASS: Liz defaults to the lowest-cost stable working Gemini model.\n";
