<?php
declare(strict_types=1);

$workflowPath = __DIR__ . '/../.github/workflows/oci-private-access-bootstrap.yml';
$scriptPath = __DIR__ . '/../scripts/oci-private-access-bootstrap.sh';
$workflow = file_get_contents($workflowPath);
$script = file_get_contents($scriptPath);
if ($workflow === false || $script === false) {
    fwrite(STDERR, "OCI private access bootstrap files missing\n");
    exit(1);
}

$workflowRequired = [
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
    "scripts/oci-private-access-bootstrap.sh",
];
foreach ($workflowRequired as $needle) {
    if (strpos($workflow, $needle) === false) {
        fwrite(STDERR, "workflow missing required contract: {$needle}\n");
        exit(1);
    }
}

$scriptRequired = [
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
foreach ($scriptRequired as $needle) {
    if (strpos($script, $needle) === false) {
        fwrite(STDERR, "script missing required contract: {$needle}\n");
        exit(1);
    }
}

if (!preg_match("/command = r'''(.*?)'''/s", $workflow, $match)) {
    fwrite(STDERR, "OCI Run Command payload not found\n");
    exit(1);
}
if (strlen($match[1]) > 4096) {
    fwrite(STDERR, "OCI Run Command payload exceeds 4096 bytes\n");
    exit(1);
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
    if (strpos($workflow, $needle) !== false || strpos($script, $needle) !== false) {
        fwrite(STDERR, "forbidden contract present: {$needle}\n");
        exit(1);
    }
}

echo "oci-private-access-bootstrap-contract: ok\n";
