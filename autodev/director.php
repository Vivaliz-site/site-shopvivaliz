<?php
declare(strict_types=1);

require_once __DIR__ . '/core/event_collector.php';
require_once __DIR__ . '/core/metrics_engine.php';
require_once __DIR__ . '/core/decision_engine.php';
require_once __DIR__ . '/evolution/conversion_analyzer.php';
require_once __DIR__ . '/evolution/ux_optimizer.php';
require_once __DIR__ . '/evolution/product_ranker.php';
require_once __DIR__ . '/actions/safe_layout_applier.php';
require_once __DIR__ . '/actions/ab_test_engine.php';
require_once __DIR__ . '/git/branch_manager.php';
require_once __DIR__ . '/git/pr_generator.php';

$sample = [
    'visits' => 1000,
    'sales' => 18,
    'checkout_start' => 120,
    'orders' => 40,
    'bounce_rate' => 0.51,
];

$metrics = autodev_metrics($sample);
$decision = autodev_decide($metrics);
$proposal = autodev_ux_optimizer($decision);
$branch = autodev_branch_name($decision);

echo json_encode([
    'ok' => true,
    'metrics' => $metrics,
    'decision' => $decision,
    'proposal' => $proposal,
    'ab_test' => autodev_create_ab_test($decision),
    'layout' => autodev_safe_layout_apply($proposal),
    'pr' => autodev_pr_payload('AutoDev Evolution System', $branch),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
