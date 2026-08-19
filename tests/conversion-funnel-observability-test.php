<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/agents/v9.2.84/app/ConversionFunnelAgent.php';

$agent = new ShopvivalizConversionFunnelAgent(dirname(__DIR__));
$method = new ReflectionMethod($agent, 'weakestTransition');
$method->setAccessible(true);

$transitions = [
    [
        'from' => 'view_item',
        'to' => 'add_to_cart',
        'source_sessions' => 20,
        'continued_sessions' => 8,
        'continuation_rate_pct' => 40.0,
        'dropoff_rate_pct' => 60.0,
    ],
    [
        'from' => 'add_to_cart',
        'to' => 'begin_checkout',
        'source_sessions' => 12,
        'continued_sessions' => 3,
        'continuation_rate_pct' => 25.0,
        'dropoff_rate_pct' => 75.0,
    ],
    [
        'from' => 'begin_checkout',
        'to' => 'add_payment_info',
        'source_sessions' => 4,
        'continued_sessions' => 1,
        'continuation_rate_pct' => 25.0,
        'dropoff_rate_pct' => 75.0,
    ],
];

$weakest = $method->invoke($agent, $transitions);
if (!is_array($weakest)) {
    fwrite(STDERR, "Expected a weakest transition when sample is sufficient\n");
    exit(1);
}
if (($weakest['from'] ?? '') !== 'add_to_cart' || ($weakest['to'] ?? '') !== 'begin_checkout') {
    fwrite(STDERR, "Weakest transition selection is incorrect\n");
    exit(1);
}
if ((int)($weakest['source_sessions'] ?? 0) !== 12 || (float)($weakest['continuation_rate_pct'] ?? -1) !== 25.0) {
    fwrite(STDERR, "Weakest transition metrics were not preserved\n");
    exit(1);
}

$insufficient = $method->invoke($agent, [[
    'from' => 'view_item',
    'to' => 'add_to_cart',
    'source_sessions' => 4,
    'continued_sessions' => 0,
    'continuation_rate_pct' => 0.0,
    'dropoff_rate_pct' => 100.0,
]]);
if ($insufficient !== null) {
    fwrite(STDERR, "Small samples must not be labeled as the weakest funnel stage\n");
    exit(1);
}

fwrite(STDOUT, "conversion-funnel-observability-test: ok\n");
