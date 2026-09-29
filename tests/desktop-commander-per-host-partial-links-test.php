<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = (string) file_get_contents($root . '/.github/workflows/dc-four-host-login-once.yml');

$required = [
    'DC_LOGIN_HOST_RESULT host=shopvivaliz-free-a1',
    'DC_LOGIN_HOST_RESULT host=always-free-arm-1787907847-26',
    'DC_LOGIN_HOST_RESULT host=fred-win',
    'DC_LOGIN_HOST_RESULT host=kocepsv',
    'Diagnose backend Desktop Commander auth failure safely',
    'if: always()',
    'Upload available private login links',
    'DC_LOGIN_MISSING_HOSTS',
];

foreach ($required as $needle) {
    if (!str_contains($workflow, $needle)) {
        fwrite(STDERR, "per-host DC login recovery contract missing: {$needle}\n");
        exit(1);
    }
}

if (!str_contains($workflow, 'if ! start_linux backend')) {
    fwrite(STDERR, "backend login failure must not abort later host login attempts\n");
    exit(1);
}
if (!str_contains($workflow, 'if ! start_windows fred-win')) {
    fwrite(STDERR, "Fred-Win login must be isolated from prior host failures\n");
    exit(1);
}
if (!str_contains($workflow, 'if ! start_windows kocepsv')) {
    fwrite(STDERR, "KOCEPSV login must be isolated from prior host failures\n");
    exit(1);
}

echo "desktop-commander-per-host-partial-links: ok\n";
