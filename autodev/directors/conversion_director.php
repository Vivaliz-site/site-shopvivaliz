<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/evolution/conversion_analyzer.php';
require_once dirname(__DIR__) . '/core/decision_engine.php';

function autodev_conversion_director(int $hours = 24): array
{
    $snapshot = autodev_conversion_snapshot($hours);
    $decision = autodev_decide($snapshot['metrics']);
    $report = [
        'director' => 'conversion_director',
        'generated_at' => date('c'),
        'hours' => $hours,
        'decision' => $decision,
        'snapshot' => $snapshot,
    ];
    autodev_write_json(dirname(__DIR__) . '/data/conversion_report.json', $report);
    return $report;
}
