<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = (string) file_get_contents($root . '/.github/workflows/dc-four-host-login-once.yml');
$docs = (string) file_get_contents($root . '/docs/DESKTOP-COMMANDER-24H.md');

$requiredDocs = [
    'VMs Ubuntu usam `0.2.48`',
    '@wonderwhy-er/desktop-commander@0.2.48 remote --persist-session',
];
foreach ($requiredDocs as $needle) {
    if (!str_contains($docs, $needle)) {
        fwrite(STDERR, "canonical Desktop Commander version contract missing from docs: {$needle}\n");
        exit(1);
    }
}

$requiredWorkflow = [
    'start_linux site 0.2.48',
    'start_linux backend 0.2.48',
    "-AuthPackageVersion '0.2.48'",
];

foreach ($requiredWorkflow as $needle) {
    if (!str_contains($workflow, $needle)) {
        fwrite(STDERR, "DC login workflow violates canonical host package version: {$needle}\n");
        exit(1);
    }
}

echo "dc-canonical-version-contract: ok\n";
