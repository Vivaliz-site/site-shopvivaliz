<?php
$root = dirname(__DIR__);
$workflow = $root . '/.github/workflows/production-runner-rescue.yml';
if (!is_file($workflow)) {
    fwrite(STDERR, "production runner rescue workflow missing\n");
    exit(1);
}
$text = (string) file_get_contents($workflow);
$required = [
    "issues:",
    "types: [opened]",
    "runs-on: ubuntu-latest",
    "environment: Production",
    "github.event.issue.title == '[production-runner-rescue]'",
    "github.event.issue.user.login == 'fredmourao-ai'",
    "github.event.issue.body == 'action=restart-idle-listener'",
    "OCI_CLI_USER",
    "OCI_CLI_TENANCY",
    "OCI_CLI_FINGERPRINT",
    "OCI_CLI_REGION",
    "OCI_CLI_KEY_CONTENT",
    "SITE_INSTANCE_NAME: shopvivaliz-free-a1",
    "ComputeInstanceAgentClient",
    "Runner.Worker",
    "RUNNER_RESCUE=refused_worker_active",
    "shopvivaliz-actions-runner.service",
    "systemctl --user restart",
    "RUNNER_RESCUE=listener_restarted",
];
foreach ($required as $needle) {
    if (strpos($text, $needle) === false) {
        fwrite(STDERR, "production runner rescue missing contract: {$needle}\n");
        exit(1);
    }
}
$forbidden = [
    "push:",
    "schedule:",
    "repository_dispatch:",
    "SHOPVIVALIZ_VM_HOST",
    "SHOPVIVALIZ_VM_SSH_KEY",
    "SHOPVIVALIZ_VM_KNOWN_HOSTS",
    "StrictHostKeyChecking=",
    "compute instance action",
    "SOFTRESET",
    "contents: write",
    "actions: write",
    "eval ",
    "|| true",
];
foreach ($forbidden as $needle) {
    if (strpos($text, $needle) !== false) {
        fwrite(STDERR, "production runner rescue contains forbidden pattern: {$needle}\n");
        exit(1);
    }
}
if (substr_count($text, "systemctl --user restart") !== 1) {
    fwrite(STDERR, "production runner rescue must have exactly one bounded listener restart\n");
    exit(1);
}
echo "production-runner-rescue-contract: ok\n";
