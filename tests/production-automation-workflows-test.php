<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

$agentWorkflow = $root . '/.github/workflows/autonomous-watchdog.yml';
$blogWorkflow = $root . '/.github/workflows/blog-publish-scheduled.yml';
$obsoleteWorkflow = $root . '/.github/workflows/ai-autonomous-executor.yml';

if (!is_file($agentWorkflow)) {
    $errors[] = 'autonomous_watchdog_missing';
} else {
    $agent = (string) file_get_contents($agentWorkflow);
    $requiredAgentFragments = [
        "cron: '*/15 * * * *'",
        '/home/ubuntu/shopvivaliz-deploy/current',
        'api/agent/real-work-orchestrator.php',
        'StrictHostKeyChecking=yes',
        'flock -w 120',
        'execution_accepted',
        'work_evidence_count',
        'latest-real-work.json',
        'actions/upload-artifact@v4',
    ];
    foreach ($requiredAgentFragments as $fragment) {
        if (!str_contains($agent, $fragment)) {
            $errors[] = 'agent_workflow_missing:' . $fragment;
        }
    }
}

if (!is_file($blogWorkflow)) {
    $errors[] = 'blog_workflow_missing';
} else {
    $blog = (string) file_get_contents($blogWorkflow);
    $requiredBlogFragments = [
        "cron: '*/15 * * * *'",
        '/home/ubuntu/shopvivaliz-deploy/current',
        'scripts/apply-real-email-campaigns-migration.php',
        'api/blog/publish-scheduled.php',
        'scripts/run-blog-email-campaigns.php',
        'StrictHostKeyChecking=yes',
        'release-sha.txt',
        'blog_queue_below_target',
        'blog_email_campaign_failed',
        'actions/upload-artifact@v4',
    ];
    foreach ($requiredBlogFragments as $fragment) {
        if (!str_contains($blog, $fragment)) {
            $errors[] = 'blog_workflow_missing:' . $fragment;
        }
    }
}

if (is_file($obsoleteWorkflow)) {
    $errors[] = 'obsolete_paused_agent_workflow_present';
}

$orchestrator = $root . '/api/agent/real-work-orchestrator.php';
if (!is_file($orchestrator)) {
    $errors[] = 'real_work_orchestrator_missing';
} else {
    $source = (string) file_get_contents($orchestrator);
    foreach (['execution_accepted', "'completed'", 'svrw_contains_forbidden_status'] as $fragment) {
        if (!str_contains($source, $fragment)) {
            $errors[] = 'orchestrator_fail_closed_missing:' . $fragment;
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, json_encode(['ok' => false, 'errors' => $errors], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

echo json_encode([
    'ok' => true,
    'agent_schedule_minutes' => 15,
    'blog_schedule_minutes' => 15,
    'execution_path' => 'oracle-active-release',
    'obsolete_workflow_removed' => true,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
