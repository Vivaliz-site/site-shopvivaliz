<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = (string) file_get_contents($root . '/.github/workflows/dc-four-host-login-once.yml');

$required = [
    'id: generate',
    'DC_LOGIN_HOST_RESULT host=shopvivaliz-free-a1',
    'DC_LOGIN_HOST_RESULT host=always-free-arm-1787907847-26',
    'DC_LOGIN_HOST_RESULT host=fred-win',
    'DC_LOGIN_HOST_RESULT host=kocepsv',
    'missing_hosts=()',
    'Upload available private login links',
    "if-no-files-found: error",
    'DC_LOGIN_MISSING_HOSTS',
];

foreach ($required as $needle) {
    if (!str_contains($workflow, $needle)) {
        fwrite(STDERR, "missing per-host DC login contract: {$needle}\n");
        exit(1);
    }
}

if (!str_contains($workflow, 'if start_linux site')) {
    fwrite(STDERR, "site login must be isolated\n");
    exit(1);
}
if (!str_contains($workflow, 'if start_linux backend')) {
    fwrite(STDERR, "backend login must be isolated\n");
    exit(1);
}
if (!str_contains($workflow, 'if make_win_tunnel 32225')) {
    fwrite(STDERR, "Fred-Win tunnel/login must be isolated\n");
    exit(1);
}
if (!str_contains($workflow, 'if make_win_tunnel 32226')) {
    fwrite(STDERR, "KOCEPSV tunnel/login must be isolated\n");
    exit(1);
}

echo "desktop-commander-per-host-links-clean: ok\n";
