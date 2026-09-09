<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = file_get_contents($root . '/scripts/google-search-console-audit.php');
if (!is_string($source)) {
    fwrite(STDERR, "Unable to read Search Console audit script\n");
    exit(1);
}

$required = [
    'require_once $root . \'/includes/google-search-console-issue-classifier.php\';',
    'gsc_classify_index_issues($index)',
];
foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Classifier wiring missing: {$needle}\n");
        exit(1);
    }
}

fwrite(STDOUT, "google-search-console-classifier-wiring-test: ok\n");
