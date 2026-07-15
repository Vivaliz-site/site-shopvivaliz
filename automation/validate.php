<?php
declare(strict_types=1);

require_once __DIR__ . '/eha/health_check.php';

function validate_final_release(): array
{
    $metrics = collect_metrics();

    $checkoutOk = (bool)($metrics['checkout_ok'] ?? false);
    $apiOk = (bool)($metrics['api_ok'] ?? false);
    $dbOk = (bool)($metrics['db_ok'] ?? false);
    $pagesOk = (bool)($metrics['pages_ok'] ?? false);

    if ($checkoutOk && $apiOk && $dbOk && $pagesOk) {
        return [
            'status' => 'READY_FOR_PRODUCTION',
            'checks' => $metrics,
        ];
    }

    return [
        'status' => 'BLOCKED',
        'checks' => $metrics,
    ];
}

$result = validate_final_release();
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(($result['status'] ?? 'BLOCKED') === 'READY_FOR_PRODUCTION' ? 0 : 1);
