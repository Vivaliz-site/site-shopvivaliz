<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/git/branch_manager.php';
require_once dirname(__DIR__) . '/git/pr_generator.php';

function autodev_gitops_director(string $decision): array
{
    $branch = autodev_branch_name($decision);
    $payload = autodev_pr_payload('AutoDev: ' . $decision, $branch);
    $report = [
        'director' => 'gitops_director',
        'generated_at' => date('c'),
        'decision' => $decision,
        'branch' => $branch,
        'pull_request' => $payload,
    ];
    autodev_write_json(dirname(__DIR__) . '/data/gitops_report.json', $report);
    return $report;
}
