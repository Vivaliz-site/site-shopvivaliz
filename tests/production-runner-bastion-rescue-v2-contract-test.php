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
    'bastion session create-port-forwarding',
    'for attempt in $(seq 1 8); do',
    'BASTION_TUNNEL_READY_ATTEMPT=',
    'BASTION_TUNNEL_AUTH_NOT_READY_ATTEMPT=',
    'for listener_attempt in $(seq 1 12); do',
    '/dev/tcp/127.0.0.1/$SITE_TUNNEL_PORT',
    'BASTION_LOCAL_LISTENER_READY_ATTEMPT=',
    'BASTION_LOCAL_LISTENER_NOT_READY_ATTEMPT=',
    '--session-ttl 1800',
    'Runner.Worker',
    'RUNNER_BASTION_RESCUE=refused_github_job_active',
    'ACTIVE_A1_JOB_COUNT_FIRST=',
    'ACTIVE_A1_JOB_COUNT_SECOND=',
    'shopvivaliz-a1-deploy',
    'etimes',
    'RUNNER_STALE_WORKER_CANDIDATE=true',
    'RUNNER_STALE_WORKER_CLEARED=true',
    'kill "$pid"',
    'shopvivaliz-actions-runner.service',
    'systemctl --user restart',
    'RUNNER_BASTION_RESCUE=listener_restarted',
    'RUNNER_SERVICE_ACTIVE=true',
    'Runner_*.log',
    'Listening for Jobs',
    'A session for this runner already exists',
    'Runner connect error',
    'RUNNER_LISTENER_CONNECTED=',
    'RUNNER_LISTENER_FAILURE_CLASS=',
    "-printf '%T@:%p\\n'",
    'cut -d: -f2-',
    "<<'REMOTE_RUNNER_RESCUE'",
    'bash -s',
    'trap \'rm -f "$marker"\' EXIT',
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
    'cat "$latest_log"',
    '"${SSH_SITE[@]}" \'set -Eeuo pipefail',
    "cut -d' ' -f2-",
];
foreach ($forbidden as $needle) {
    if (str_contains($text, $needle)) {
        fwrite(STDERR, "forbidden rescue behavior: {$needle}\n");
        exit(1);
    }
}

$githubJobGuard = 'if [ "$ACTIVE_A1_JOB_COUNT_FIRST" -ne 0 ] || [ "$ACTIVE_A1_JOB_COUNT_SECOND" -ne 0 ]; then';
$workerBranch = 'if [ "$workers" -gt 0 ]; then';
$githubJobGuardPos = strpos($text, $githubJobGuard);
$workerBranchPos = strpos($text, $workerBranch);
if ($githubJobGuardPos === false || $workerBranchPos === false || $githubJobGuardPos > $workerBranchPos) {
    fwrite(STDERR, "GitHub active-job guard must refuse rescue before local Runner.Worker branching\n");
    exit(1);
}

echo "PRODUCTION_RUNNER_BASTION_RESCUE_CONTRACT=PASS\n";
