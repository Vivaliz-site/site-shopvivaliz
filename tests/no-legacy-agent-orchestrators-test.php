<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

$forbidden = [
    'api/agent/cron-dispatcher.php',
    'api/agent/external-trigger.php',
    'api/agent/autonomous-report.php',
    'api/orchestrator/director.php',
    'api/orchestrator/scheduler.php',
    'api/orchestrator/queue.php',
    'agents/v9.2.84/app/Olist198SyncAgent.php',
    'agents/v9.2.84/api/agent/olist-198-sync.php',
    'agents/v9.2.84/app/GitHubPullUpdateAgent.php',
    'agents/v9.2.84/app/SafeMigrationRepairAgent.php',
    'agents/v9.2.84/app/OlistImageRepairAgent.php',
    'agents/v9.2.84/app/AutonomousReportAgent.php',
    'agents/v9.2.84/app/SelfTestAgent.php',
    'agents/v9.2.84/app/LoopStarterAgent.php',
    'agents/v9.2.84/app/AutonomousWatchdogAgent.php',
    'agents/v9.2.84/app/MediaMemoryAgent.php',
    'agents/v9.2.85/app/UpdateStatusAgent.php',
    'agents/v9.2.84/api/agent/autonomous-watchdog.php',
    'agents/v9.2.84/api/agent/autonomous-report.php',
    'agents/v9.2.84/api/agent/media-quality.php',
    'agents/v9.2.84/api/agent/media-mismatch.php',
    'agents/v9.2.84/api/agent/external-trigger.php',
];
foreach ($forbidden as $relative) {
    if (is_file($root . '/' . $relative)) {
        $errors[] = 'legacy_agent_file_present:' . $relative;
    }
}

$admin = $root . '/admin/orchestrator.php';
if (!is_file($admin)) {
    $errors[] = 'verified_agent_admin_missing';
} else {
    $source = (string)file_get_contents($admin);
    foreach ([
        'production-agent-registry.php',
        'api/agent/real-work-orchestrator.php',
        'latest-agent-cycle.json',
        'execution_accepted',
        'flock -w 120',
    ] as $required) {
        if (!str_contains($source, $required)) {
            $errors[] = 'verified_agent_admin_missing:' . $required;
        }
    }
    foreach (['cron-dispatcher.php', 'storage/orchestrator/queue.json', "'dry_run'", 'Task desconhecida'] as $legacy) {
        if (str_contains($source, $legacy)) {
            $errors[] = 'verified_agent_admin_contains_legacy:' . $legacy;
        }
    }
}

$fakeFragments = [
    'Produto Premium #1',
    "'status' => 'dry_run'",
    'PAYMENT ID REAL no formato correto',
];
foreach (glob($root . '/agents/*/app/*.php') ?: [] as $path) {
    $source = (string)file_get_contents($path);
    foreach ($fakeFragments as $fragment) {
        if (str_contains($source, $fragment)) {
            $errors[] = 'agent_fake_fragment:' . basename($path) . ':' . $fragment;
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, json_encode(['ok' => false, 'errors' => $errors], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

echo json_encode([
    'ok' => true,
    'legacy_orchestrators' => 0,
    'legacy_agent_files' => 0,
    'admin_source' => 'latest-agent-cycle.json',
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
