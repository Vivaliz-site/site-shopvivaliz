<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$audit = file_get_contents($root . '/scripts/google-search-console-audit.php');
$workflow = file_get_contents($root . '/.github/workflows/google-search-console-audit.yml');
if (!is_string($audit) || !is_string($workflow)) {
    fwrite(STDERR, "Unable to read GSC audit wiring files\n");
    exit(1);
}

$requiredAudit = [
    "require_once \$root . '/scripts/lib/google_search_console_retry.php';",
    'gsc_url_inspection_request_with_retry(',
];
foreach ($requiredAudit as $needle) {
    if (!str_contains($audit, $needle)) {
        fwrite(STDERR, "Missing audit retry wiring: {$needle}\n");
        exit(1);
    }
}

$requiredWorkflow = [
    "'scripts/lib/google_search_console_retry.php'",
    "'tests/google-search-console-transient-retry-test.php'",
    'php tests/google-search-console-transient-retry-test.php',
];foreach ($requiredWorkflow as $needle) {
    if (!str_contains($workflow, $needle)) {
        fwrite(STDERR, "Missing workflow retry contract: {$needle}\n");
        exit(1);
    }
}

fwrite(STDOUT, "google-search-console-transient-retry-wiring-test: ok\n");
