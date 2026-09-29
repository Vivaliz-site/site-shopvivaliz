<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = (string) file_get_contents($root . '/.github/workflows/dc-four-host-login-once.yml');

$required = [
    'reauth-primary.log',
    'DC_PRIMARY_AUTH_DIAG',
    '- name: Upload available private login links',
    'if: always()',
    'if-no-files-found: warn',
];

foreach ($required as $needle) {
    if (!str_contains($workflow, $needle)) {
        fwrite(STDERR, "DC login must preserve partial links and primary failure evidence: {$needle}\n");
        exit(1);
    }
}

$copyPos = strpos($workflow, 'reauth-primary.log');
$fallbackPos = strpos($workflow, 'DC_LINUX_PRIMARY_HELPER=fallback');
if ($copyPos === false || $fallbackPos === false || $copyPos > $fallbackPos) {
    fwrite(STDERR, "primary auth evidence must be preserved before fallback overwrites the session log\n");
    exit(1);
}

echo "dc-partial-links-primary-evidence: ok\n";
