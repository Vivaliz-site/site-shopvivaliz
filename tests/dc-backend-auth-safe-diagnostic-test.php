<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = (string) file_get_contents($root . '/.github/workflows/dc-four-host-login-once.yml');

$required = [
    'diagnose_host SITE',
    'diagnose_host BACKEND',
    'tag = f"DC_{prefix}_AUTH_DIAG"',
    'SSH_BACKEND=(',
    'reauth-session.log',
    'device_json',
    'verification_url_prompt',
    'waiting_authorization',
    'npm_error',
    'network_error',
    'auth_error',
];

foreach ($required as $needle) {
    if (!str_contains($workflow, $needle)) {
        fwrite(STDERR, "four-host login failure diagnostics missing safe host evidence: {$needle}\n");
        exit(1);
    }
}

echo "dc-backend-auth-safe-diagnostic: ok\n";
