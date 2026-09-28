<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = $root . '/.github/workflows/production-runner-bastion-rescue.yml';
if (!is_file($workflow)) {
    fwrite(STDERR, "missing production runner Bastion rescue workflow\n");
    exit(1);
}
$text = (string) file_get_contents($workflow);

$required = [
    'name: Production Runner Bastion Rescue',
    "github.event.issue.title == '[production-runner-bastion-rescue]'",
    "github.event.issue.user.login == 'fredmourao-ai'",
    "github.event.issue.body == 'action=restart-idle-listener-private'",
    'runs-on: ubuntu-latest',
    'environment: Production',
    'actions: write',
    'SITE_INSTANCE_NAME: shopvivaliz-free-a1',
    'SITE_PRIVATE_IP: 10.0.1.112',
    'BASTION_TUNNEL_MAX_ATTEMPTS: "6"',
    'BASTION_TUNNEL_RETRY_SECONDS: "5"',
    'bastion session create-port-forwarding',
    'for attempt in $(seq 1 "$BASTION_TUNNEL_MAX_ATTEMPTS")',
    'BASTION_TUNNEL_AUTH_RETRY=',
    'test "$tunnel_opened" = true',
    '--session-ttl 1800',
    'Runner.Worker',
    'RUNNER_BASTION_RESCUE=refused_worker_active',
    'shopvivaliz-actions-runner.service',
    'systemctl --user restart',
    'RUNNER_BASTION_RESCUE=listener_restarted',
    'RUNNER_SERVICE_ACTIVE=true',
    'gh workflow run master-production-pipeline.yml',
    '-f confirmation=DEPLOY',
    'bastion session delete',
    'bastion bastion update',
    'shred -u',
];
foreach ($required as $needle) {
    if (!str_contains($text, $needle)) {
        fwrite(STDERR, "missing contract marker: {$needle}\n");
        exit(1);
    }
}

$forbidden = [
    '137.131.156.17',
    'ubuntu@137.131.',
    'runs-on: [self-hosted',
    'systemctl reboot',
    'shutdown ',
    'kill -9',
    'pkill -9',
    '|| true',
    'exit 0',
    '--session-ttl 900',
];
foreach ($forbidden as $needle) {
    if (str_contains($text, $needle)) {
        fwrite(STDERR, "forbidden rescue behavior: {$needle}\n");
        exit(1);
    }
}

echo "PRODUCTION_RUNNER_BASTION_RESCUE_CONTRACT=PASS\n";
