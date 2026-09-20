<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/backend-vm-oci-control.yml';
if (!is_file($workflowPath)) {
    fwrite(STDERR, "backend OCI workflow missing\n");
    exit(1);
}
$text = (string) file_get_contents($workflowPath);

$required = [
    'runs-on: ubuntu-latest',
    'environment: Production',
    "github.event.issue.number == 1586",
    "github.event.comment.user.login == 'fredmourao-ai'",
    "startsWith(github.event.comment.body, '/backend-oci ')",
    'BACKEND_INSTANCE_NAME: always-free-arm-1787907847-26',
    'test "$backend_ip" = "10.0.1.38"',
    'OCI_CLI_KEY_CONTENT',
    'SHOPVIVALIZ_VM_SSH_KEY',
    'StrictHostKeyChecking=yes',
    'create-port-forwarding',
    '--target-port 22',
    '--session-ttl 1800',
    'HostKeyAlias="$BACKEND_PRIVATE_IP"',
    'BACKEND_OCI_IDENTITY=PASS',
    'BASTION_ALLOWLIST_RESTORED=PASS',
    'browser_status',
    'browser_health',
    'browser_install',
    'browser_restart',
    '/home/ubuntu/shopvivaliz-browser-worker/supervisor.sh',
    'http://127.0.0.1:17777/health',
    'bash -s -- backend',
];
foreach ($required as $needle) {
    if (!str_contains($text, $needle)) {
        fwrite(STDERR, "backend OCI contract missing: {$needle}\n");
        exit(1);
    }
}

$forbidden = [
    'pull_request_target:',
    'repository_dispatch:',
    'contents: write',
    'actions: write',
    'StrictHostKeyChecking=no',
    'StrictHostKeyChecking=accept-new',
    '0.0.0.0:22',
    'GatewayPorts=yes',
    'tailscale funnel',
    'shell_command',
    '|| true',
];
foreach ($forbidden as $needle) {
    if (str_contains($text, $needle)) {
        fwrite(STDERR, "backend OCI forbidden pattern: {$needle}\n");
        exit(1);
    }
}

if (substr_count($text, "allowed = {") !== 1) {
    fwrite(STDERR, "backend OCI must have one explicit action allowlist\n");
    exit(1);
}
if (!str_contains($text, '"identity", "disk", "runtime_status", "repo_status"')) {
    fwrite(STDERR, "backend OCI core operations allowlist missing\n");
    exit(1);
}

echo "BACKEND_VM_OCI_CONTROL_CONTRACT=PASS\n";
