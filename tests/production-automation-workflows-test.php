<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

require_once $root . '/includes/production-agent-registry.php';
$registry = svpa_registry();
$registered = (array)($registry['agents'] ?? []);
$expectedIds = [
    'operational_work',
    'catalog_metadata',
    'conversion_funnel',
    'media_quality',
    'media_mismatch',
];

if (array_keys($registered) !== $expectedIds) {
    $errors[] = 'production_agent_registry_ids_invalid';
}
if (($registry['trigger_mode'] ?? null) !== 'scheduled') {
    $errors[] = 'production_agent_trigger_mode_invalid';
}
if (($registry['schedule_minutes'] ?? null) !== 60) {
    $errors[] = 'production_agent_schedule_minutes_invalid';
}

$registeredFiles = [];
foreach ($registered as $id => $definition) {
    $relative = (string)($definition['file'] ?? '');
    $class = (string)($definition['class'] ?? '');
    $absolute = $root . '/' . ltrim($relative, '/');
    $registeredFiles[] = str_replace('\\', '/', $relative);
    if (!is_file($absolute)) {
        $errors[] = 'registered_agent_file_missing:' . $id;
        continue;
    }
    $source = (string)file_get_contents($absolute);
    if ($class === '' || !str_contains($source, 'final class ' . $class)) {
        $errors[] = 'registered_agent_class_invalid:' . $id;
    }
    $forbiddenPatterns = [
        '/function\s+simulate[A-Za-z0-9_]*\s*\(/i',
        '/function\s+mock[A-Za-z0-9_]*\s*\(/i',
        '/[\'\"]status[\'\"]\s*=>\s*[\'\"]dry[_-]?run[\'\"]/i',
        '/[\'\"]status[\'\"]\s*=>\s*[\'\"]simulated[\'\"]/i',
        '/Produto Premium/i',
    ];
    foreach ($forbiddenPatterns as $pattern) {
        if (preg_match($pattern, $source) === 1) {
            $errors[] = 'registered_agent_contains_simulation_path:' . $id . ':' . $pattern;
        }
    }
}

sort($registeredFiles);
$agentFiles = glob($root . '/agents/v9.2.84/app/*Agent.php') ?: [];
$actualFiles = array_map(
    static fn(string $path): string => str_replace('\\', '/', substr($path, strlen($root) + 1)),
    $agentFiles
);
sort($actualFiles);
if ($actualFiles !== $registeredFiles) {
    $errors[] = 'unregistered_or_missing_agent_files:' . json_encode([
        'registered' => $registeredFiles,
        'actual' => $actualFiles,
    ], JSON_UNESCAPED_SLASHES);
}

$forbiddenLegacyPaths = [
    'api/agent/cron-dispatcher.php',
    'api/agent/external-trigger.php',
    'api/agent/autonomous-report.php',
    'agents/v9.2.84/app/Olist198SyncAgent.php',
    'agents/v9.2.84/api/agent/olist-198-sync.php',
    'agents/v9.2.84/app/GitHubPullUpdateAgent.php',
    'agents/v9.2.84/api/agent/autonomous-watchdog.php',
    'agents/v9.2.84/api/agent/autonomous-report.php',
    'agents/v9.2.84/api/agent/media-quality.php',
    'agents/v9.2.84/api/agent/media-mismatch.php',
    'agents/v9.2.84/api/agent/external-trigger.php',
];
foreach ($forbiddenLegacyPaths as $relative) {
    if (is_file($root . '/' . $relative)) {
        $errors[] = 'legacy_agent_surface_present:' . $relative;
    }
}

$agentWorkflow = $root . '/.github/workflows/autonomous-watchdog.yml';
if (!is_file($agentWorkflow)) {
    $errors[] = 'autonomous_watchdog_missing';
} else {
    $agent = (string)file_get_contents($agentWorkflow);
    $requiredAgentFragments = [
        'workflow_dispatch:',
        '/home/ubuntu/shopvivaliz-deploy/current',
        'api/agent/real-work-orchestrator.php',
        'includes/production-agent-registry.php',
        'StrictHostKeyChecking=yes',
        'flock -w 120',
        'execution_accepted',
        'work_evidence_count',
        'active_agent_count',
        'latest-agent-cycle.json',
        'agent_evidence_stale',
        'actions/upload-artifact@v4',
    ];
    foreach ($requiredAgentFragments as $fragment) {
        if (!str_contains($agent, $fragment)) {
            $errors[] = 'agent_workflow_missing:' . $fragment;
        }
    }
    if (!str_contains($agent, "cron: '19 * * * *'")) {
        $errors[] = 'production_agent_workflow_schedule_invalid';
    }
    foreach ($expectedIds as $id) {
        if (!str_contains($agent, "'" . $id . "'")) {
            $errors[] = 'agent_workflow_registry_missing:' . $id;
        }
    }
}

$orchestrator = $root . '/api/agent/real-work-orchestrator.php';
if (!is_file($orchestrator)) {
    $errors[] = 'real_work_orchestrator_missing';
} else {
    $source = (string)file_get_contents($orchestrator);
    foreach ([
        'production-agent-registry.php',
        'svrw_persist_cycle',
        'latest-agent-cycle.json',
        'minimum_work_evidence_count',
        'execution_accepted',
        'svrw_contains_forbidden_status',
    ] as $fragment) {
        if (!str_contains($source, $fragment)) {
            $errors[] = 'orchestrator_contract_missing:' . $fragment;
        }
    }
}

$blogWorkflow = $root . '/.github/workflows/blog-publish-scheduled.yml';
if (!is_file($blogWorkflow)) {
    $errors[] = 'blog_workflow_missing';
} else {
    $blog = (string)file_get_contents($blogWorkflow);
    foreach ([
        "cron: '*/15 * * * *'",
        '/home/ubuntu/shopvivaliz-deploy/current',
        'api/blog/publish-scheduled.php',
        'scripts/run-blog-email-campaigns.php',
        'blog_queue_below_target',
        'blog_email_campaign_failed',
    ] as $fragment) {
        if (!str_contains($blog, $fragment)) {
            $errors[] = 'blog_workflow_missing:' . $fragment;
        }
    }
}

$manifestPath = $root . '/agents/v9.2.84/manifest.json';
$manifest = is_file($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : null;
if (!is_array($manifest)) {
    $errors[] = 'production_agent_manifest_invalid';
} else {
    $manifestIds = array_map(static fn(array $agent): string => (string)($agent['id'] ?? ''), (array)($manifest['agents'] ?? []));
    if ($manifestIds !== $expectedIds) {
        $errors[] = 'production_agent_manifest_ids_invalid';
    }
    if (($manifest['trigger_mode'] ?? null) !== 'scheduled' || ($manifest['schedule_minutes'] ?? null) !== 60) {
        $errors[] = 'production_agent_manifest_trigger_invalid';
    }
    if (($manifest['requirements']['minimum_work_evidence_count'] ?? 0) < 12) {
        $errors[] = 'production_agent_manifest_evidence_too_weak';
    }
}

if ($errors !== []) {
    fwrite(STDERR, json_encode(['ok' => false, 'errors' => $errors], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

echo json_encode([
    'ok' => true,
    'active_agent_count' => count($registered),
    'active_agents' => array_keys($registered),
    'agent_trigger_mode' => 'scheduled',
    'agent_schedule_minutes' => 60,
    'blog_schedule_minutes' => 15,
    'execution_path' => 'oracle-active-release',
    'unregistered_agent_files' => 0,
    'legacy_agent_surfaces' => 0,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
