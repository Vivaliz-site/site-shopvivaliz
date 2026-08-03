<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$forbiddenFiles = [
    'api/olist/local-server.py',
    'api/olist/run-local-server.ps1',
    'api/olist/test-local-api.ps1',
    'api/olist/LOCAL-API-README.md',
    'api/olist/QUICK-START.txt',
];

foreach ($forbiddenFiles as $relative) {
    if (file_exists($root . '/' . $relative)) {
        fwrite(STDERR, "FAIL: Olist mock artifact returned: {$relative}\n");
        exit(1);
    }
}

$olistDir = $root . '/api/olist';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($olistDir, FilesystemIterator::SKIP_DOTS));
$forbiddenPatterns = [
    '/local[_-]?mock/i',
    '/API Olist Local/i',
    '/mock[^\n]{0,40}(order|product|oauth|webhook)/i',
];

foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $contents = file_get_contents($file->getPathname());
    if (!is_string($contents)) {
        continue;
    }
    foreach ($forbiddenPatterns as $pattern) {
        if (preg_match($pattern, $contents) === 1) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            fwrite(STDERR, "FAIL: simulated Olist implementation found in {$relative}\n");
            exit(1);
        }
    }
}

echo "no-olist-mock-suite: ok\n";
