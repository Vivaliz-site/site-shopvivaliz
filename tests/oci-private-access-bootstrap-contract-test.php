<?php
declare(strict_types=1);

$path = __DIR__ . '/../.github/workflows/oci-private-access-bootstrap.yml';
$text = file_get_contents($path);
if ($text === false) {
    fwrite(STDERR, "workflow missing\n");
    exit(1);
}

$required = [
    "name: OCI Private Access Bootstrap",
    "runs-on: ubuntu-latest",
    "github.event.issue.title == '[oci-private-access-bootstrap]'",
    "github.event.issue.body == 'action=install-both-vms'",
    "OCI_CLI_USER",
    "OCI_CLI_TENANCY",
    "OCI_CLI_FINGERPRINT",
    "OCI_CLI_REGION",
    "OCI_CLI_KEY_CONTENT",
    "BACKEND_INSTANCE_NAME: always-free-arm-1787907847-26",
    "ComputeInstanceAgentClient",
    "/home/ubuntu/.ssh/shopvivaliz-free-a1-monitor",
    "10.0.1.112",
    "setup-rustdesk-remote.sh",
    "setup-agent-ssh.sh",
    "StrictHostKeyChecking=yes",
    "PRIVATE_ACCESS_BOOTSTRAP=PASS",
    "AGENT_SSH_BACKEND=PASS",
    "AGENT_SSH_SITE=PASS",
    "RUSTDESK_SERVER=PASS",
];
foreach ($required as $needle) {
    if (strpos($text, $needle) === false) {
        fwrite(STDERR, "missing required contract: {$needle}\n");
        exit(1);
    }
}

$forbidden = [
    "runs-on: [self-hosted",
    "contents: write",
    "actions: write",
    "StrictHostKeyChecking=accept-new",
    "StrictHostKeyChecking=no",
    "|| true",
    "set +e",
    "eval ",
];
foreach ($forbidden as $needle) {
    if (strpos($text, $needle) !== false) {
        fwrite(STDERR, "forbidden contract present: {$needle}\n");
        exit(1);
    }
}

echo "oci-private-access-bootstrap-contract: ok\n";
