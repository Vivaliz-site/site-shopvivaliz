<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$yaml = file_get_contents($root . '/.github/workflows/google-search-console-audit.yml');
if ($yaml === false) {
    fwrite(STDERR, "workflow unreadable\n");
    exit(1);
}
$required = [
    'ORACLE_VM_SSH_KEY',
    'ORACLE_VM_KNOWN_HOSTS',
    'ubuntu@163.176.103.253',
    'http://127.0.0.1:8080/sitemap.xml',
    'reports/google-search-console-sitemap.xml',
    '--sitemap-file=reports/google-search-console-sitemap.xml',
];
foreach ($required as $needle) {
    if (strpos($yaml, $needle) === false) {
        fwrite(STDERR, "missing trusted sitemap workflow contract: {$needle}\n");
        exit(1);
    }
}
echo "google-search-console-workflow-trusted-sitemap-test: ok\n";
