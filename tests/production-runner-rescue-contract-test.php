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
    "derived_host=",
    "SHOPVIVALIZ_VM_KNOWN_HOSTS",
    "SHOPVIVALIZ_VM_SSH_KEY",
    "StrictHostKeyChecking=yes",
    "shopvivaliz-actions-runner.service",
    "worker_pattern=",
    "ps -eo args=",\n    "awk -v pattern=",
    "RUNNER_RESCUE=refused_worker_active",
    "systemctl --user restart",
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
    "StrictHostKeyChecking=accept-new",
    "contents: write",
    "actions: write",
    "eval ",\n    "|| true",
    "137.131.",
    "136.248.",
    "SHOPVIVALIZ_VM_HOST",
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
