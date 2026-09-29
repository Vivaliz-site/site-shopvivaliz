<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$one = (string) file_get_contents($root . '/.github/workflows/dc-four-host-login-once.yml');

if (substr_count($one, 'bastion session create-port-forwarding') !== 1) {
    fwrite(STDERR, "DC login one-shot must create exactly one OCI Bastion port-forwarding session\n");
    exit(1);
}
$required = [
    '-L "$BACKEND_TUNNEL_PORT:$BACKEND_PRIVATE_IP:22"',
    'HostKeyAlias="$SITE_PRIVATE_IP"',
    'ubuntu@127.0.0.1',
    'DC_BACKEND_VIA_SITE_TUNNEL=PASS',
];
foreach ($required as $needle) {
    if (!str_contains($one, $needle)) {
        fwrite(STDERR, "DC login one-shot missing single-bastion backend hop: {$needle}\n");
        exit(1);
    }
}
if (str_contains($one, 'create_tunnel BACKEND_SESSION_ID')) {
    fwrite(STDERR, "DC login one-shot must not create a second Bastion session for backend\n");
    exit(1);
}
echo "dc-four-host-login-single-bastion: ok\n";
