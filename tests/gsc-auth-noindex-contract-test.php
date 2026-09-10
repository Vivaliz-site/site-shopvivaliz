<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$htaccess = (string)file_get_contents($root . '/.htaccess');

$required = [
    'SetEnvIf Request_URI "^/auth/" SVRT_NOINDEX=1',
    'Header always set X-Robots-Tag "noindex, nofollow, noarchive" env=SVRT_NOINDEX',
];
foreach ($required as $needle) {
    if (!str_contains($htaccess, $needle)) {
        fwrite(STDERR, "Auth pages must emit a crawler noindex header: {$needle}\n");
        exit(1);
    }
}
fwrite(STDOUT, "gsc-auth-noindex-contract-test: ok\n");
