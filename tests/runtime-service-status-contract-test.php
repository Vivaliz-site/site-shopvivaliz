<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$scriptPath = $root . '/scripts/runtime-service-status.sh';
$workflowPath = $root . '/.github/workflows/shopvivaliz-remote-access.yml';
$hostAccessPath = $root . '/docs/knowledge/host-access.md';
$agentRulesPath = $root . '/docs/knowledge/agent-rules.md';
$errors = [];

foreach ([$scriptPath, $workflowPath, $hostAccessPath, $agentRulesPath] as $path) {
    if (!is_file($path)) {
        $errors[] = "missing file: {$path}";
    }
}
if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

$script = (string) file_get_contents($scriptPath);
$workflow = (string) file_get_contents($workflowPath);
$hostAccess = (string) file_get_contents($hostAccessPath);
$agentRules = (string) file_get_contents($agentRulesPath);

foreach ([
    'report_required_active shopvivaliz-catalog-reconcile.timer',
    'report_oneshot_success shopvivaliz-catalog-reconcile.service',
    'EXPECTED=inactive-sender-block',
    'SENDER_BLOCK=active',
    'report_required_active mei-mg-email-api.service',
    'report_required_active mei-mg-email-monitor.service',
    'report_required_active mei-mg-email-brevo-reconciler.service',
    'report_expected_absent shopvivaliz-products-active-sync.service',
    'report_expected_absent shopvivaliz-24x7.service',
    'report_expected_absent shopvivaliz-mcp.service',
] as $needle) {
    if (!str_contains($script, $needle)) {
        $errors[] = "runtime inventory contract missing: {$needle}";
    }
}

if (!str_contains($workflow, 'bash scripts/runtime-service-status.sh site')) {
    $errors[] = 'site remote runtime_status must use canonical runtime inventory';
}

foreach ([
    '- mlrr_rollout',
    '"mlrr_rollout"',
    'scripts/mlrr-production-ops.sh prepare',
    'scripts/mlrr-production-ops.sh shadow',
    'scripts/mlrr-production-ops.sh validate',
    'scripts/mlrr-production-ops.sh preflight',
] as $needle) {
    if (!str_contains($workflow, $needle)) {
        $errors[] = "MLRR production bridge contract missing: {$needle}";
    }
}
if (!str_contains($workflow, "target != 'shopvivaliz-free-a1' && steps.req.outputs.action != 'mlrr_rollout'")) {
    $errors[] = 'MLRR rollout must never trigger backend SSH setup';
}
if (!str_contains($workflow, "bash -s -- backend") || !str_contains($workflow, '< scripts/runtime-service-status.sh')) {
    $errors[] = 'backend remote runtime_status must use canonical runtime inventory';
}

foreach ([
    'not-found',
    'shopvivaliz-catalog-reconcile.timer',
    'mei-mg-email-worker.service',
    'sender_blocked.pause',
] as $needle) {
    if (!str_contains($hostAccess, $needle)) {
        $errors[] = "host-access runtime semantics missing: {$needle}";
    }
}

if (!str_contains($agentRules, 'LoadState=not-found') || !str_contains($agentRules, 'oneshot')) {
    $errors[] = 'agent rules must distinguish absent legacy units and expected inactive oneshots';
}

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "runtime-service-status-contract: ok\n";
