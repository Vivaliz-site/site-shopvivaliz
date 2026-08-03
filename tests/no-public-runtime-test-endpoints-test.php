<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$forbiddenFiles = [
    'test-production-readiness.php',
    '.tmp_shopvivaliz/sample.csv',
];

foreach ($forbiddenFiles as $relative) {
    if (is_file($root . '/' . $relative)) {
        fwrite(STDERR, "Forbidden public test/runtime artifact exists: {$relative}\n");
        exit(1);
    }
}

$tracked = shell_exec('cd ' . escapeshellarg($root) . ' && git ls-files 2>/dev/null');
if (!is_string($tracked)) {
    fwrite(STDERR, "Unable to inspect tracked files.\n");
    exit(1);
}

foreach (preg_split('/\R/', trim($tracked)) ?: [] as $relative) {
    if ($relative === '') {
        continue;
    }
    if (preg_match('#^(?:test|teste)[^/]*\.php$#i', $relative) === 1) {
        fwrite(STDERR, "Root-level PHP test endpoint is forbidden: {$relative}\n");
        exit(1);
    }
    if (str_starts_with($relative, '.tmp_shopvivaliz/')) {
        fwrite(STDERR, "Temporary ShopVivaliz artifact is tracked: {$relative}\n");
        exit(1);
    }
}

echo "OK: no public runtime test endpoints or tracked temporary artifacts\n";
