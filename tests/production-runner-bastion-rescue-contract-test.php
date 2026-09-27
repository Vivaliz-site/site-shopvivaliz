<?php
$root = dirname(__DIR__);
$workflow = $root . '/.github/workflows/production-runner-bastion-rescue.yml';
if (!is_file($workflow)) { fwrite(STDERR, "workflow missing\n"); exit(1); }
$text = (string) file_get_contents($workflow);
$required = [
    "github.event.issue.title == '[production-runner-bastion-rescue]'",
    "github.event.issue.user.login == 'fredmourao-ai'",
    "github.event.issue.body == 'action=restart-idle-listener'",
    "runs-on: ubuntu-latest",
    "environment: Production",
    "SITE_PRIVATE_IP: 10.0.1.112",
    "bastion session create-port-forwarding",
    "Runner.Worker",
    "RUNNER_BASTION_RESCUE=skipped_worker_active",
    "shopvivaliz-actions-runner.service",
    "systemctl --user restart",
    "RUNNER_BASTION_RESCUE=listener_restarted",
    "RUNNER_SERVICE_ACTIVE=true",
    "Cleanup temporary Bastion access and credentials",
];
foreach ($required as $needle) {
    if (strpos($text, $needle) === false) {
        fwrite(STDERR, "missing contract token: $needle\n");
        exit(1);
    }
}
$forbidden = [
    "runs-on: [self-hosted",
    "systemctl reboot",
    "shutdown ",
    "pkill -9",
    "kill -9",
];
foreach ($forbidden as $needle) {
    if (strpos($text, $needle) !== false) {
        fwrite(STDERR, "forbidden rescue token: $needle\n");
        exit(1);
    }
}
echo "PRODUCTION_RUNNER_BASTION_RESCUE_CONTRACT=PASS\n";
