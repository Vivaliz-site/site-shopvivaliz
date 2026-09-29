<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = (string) file_get_contents($root . '/.github/workflows/dc-four-host-login-once.yml');

$required = [
    'if bash -lc "$tunnel"; then',
    'listener_ready=false',
    'tunnel_ready=true',
    'if [ "$tunnel_ready" != true ]; then',
    'BASTION_TUNNEL_AUTH_NOT_READY_ATTEMPT=',
    'HostKeyAlias=127.0.0.1',
    'HostKeyAlias="$BACKEND_PRIVATE_IP"',
];

foreach ($required as $needle) {
    if (!str_contains($workflow, $needle)) {
        fwrite(STDERR, "DC one-shot is not using the proven Bastion tunnel pattern: {$needle}\n");
        exit(1);
    }
}

if (str_contains($workflow, 'if ! bash -lc "$tunnel"; then')) {
    fwrite(STDERR, "DC one-shot must not test the listener after a failed Bastion SSH authentication\n");
    exit(1);
}

echo "dc-bastion-proven-pattern: ok\n";
