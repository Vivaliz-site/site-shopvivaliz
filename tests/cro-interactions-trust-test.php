<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../js/cro-interactions.js');
if (!is_string($source)) {
    fwrite(STDERR, "FAIL: cro-interactions.js could not be read\n");
    exit(1);
}

$forbidden = [
    'VOLTEI5',
    'GOAL_COUPON',
    'GOAL_SHIPPING',
    'exit-intent-overlay',
    'mini-cart-drawer',
    'cupom de <strong>5% de desconto</strong>',
];
foreach ($forbidden as $snippet) {
    if (str_contains($source, $snippet)) {
        fwrite(STDERR, "FAIL: stale CRO promise/flow remains: {$snippet}\n");
        exit(1);
    }
}

$required = [
    "fetch('/api/settings/free-shipping.php', { cache: 'no-store' })",
    'if (!config || !config.enabled',
    "wrapper.style.display = 'none'",
    "initStickyAddToCart();",
    "initSkeletonLoaders();",
    "initImageHoverZoom();",
    "initFreeShippingProgress();",
];
foreach ($required as $snippet) {
    if (!str_contains($source, $snippet)) {
        fwrite(STDERR, "FAIL: required CRO safety behavior missing: {$snippet}\n");
        exit(1);
    }
}

echo "cro-interactions-trust: ok\n";
