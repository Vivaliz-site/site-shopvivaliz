<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/evolution/ux_optimizer.php';
require_once dirname(__DIR__) . '/actions/safe_layout_applier.php';
require_once dirname(__DIR__) . '/actions/ab_test_engine.php';

function autodev_ux_director(string $decision): array
{
    $proposal = autodev_ux_optimizer($decision);
    $layout = autodev_safe_layout_apply($proposal);
    $ab = $decision !== 'no_action' ? autodev_create_ab_test($decision) : ['status' => 'skipped'];
    $report = [
        'director' => 'ux_director',
        'generated_at' => date('c'),
        'decision' => $decision,
        'proposal' => $proposal,
        'layout' => $layout,
        'ab_test' => $ab,
    ];
    autodev_write_json(dirname(__DIR__) . '/data/ux_report.json', $report);
    return $report;
}
