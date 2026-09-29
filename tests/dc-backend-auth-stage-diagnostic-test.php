<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = (string) file_get_contents($root . '/.github/workflows/dc-four-host-login-once.yml');

$required = [
    '"startup_started"',
    '"local_mcp_connect_started"',
    '"local_mcp_connected"',
    '"local_mcp_not_found"',
    '"local_mcp_start_failed"',
    '"remote_connect_started"',
    '"remote_connect_succeeded"',
    '"auth_started"',
    '"device_startup_failed"',
    '"command_not_found"',
    '"module_not_found"',
    '"permission_denied"',
];

foreach ($required as $needle) {
    if (!str_contains($workflow, $needle)) {
        fwrite(STDERR, "DC backend auth stage diagnostic missing: {$needle}\n");
        exit(1);
    }
}

echo "dc-backend-auth-stage-diagnostic: ok\n";
