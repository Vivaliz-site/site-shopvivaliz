<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/core/metrics_engine.php';
require_once __DIR__ . '/directors/conversion_director.php';
require_once __DIR__ . '/directors/ux_director.php';
require_once __DIR__ . '/directors/gitops_director.php';

$hours = min(168, max(1, (int)($argv[1] ?? $_GET['hours'] ?? 24)));
$conversion = autodev_conversion_director($hours);
$decision = (string)($conversion['decision'] ?? 'no_action');
$ux = autodev_ux_director($decision);
$gitops = autodev_gitops_director($decision);
$metrics = autodev_calculate_metrics($hours);
autodev_save_metrics_snapshot($metrics);

echo json_encode([
    'ok' => true,
    'hours' => $hours,
    'metrics' => $metrics,
    'decision' => $decision,
    'conversion' => $conversion,
    'ux' => $ux,
    'gitops' => $gitops,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
