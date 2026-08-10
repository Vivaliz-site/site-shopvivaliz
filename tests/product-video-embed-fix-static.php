<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$head = file_get_contents($root . '/includes/head-analytics.php');
$fix = file_get_contents($root . '/includes/product-video-embed-fix.php');

if (!is_string($head) || !str_contains($head, "require_once __DIR__ . '/product-video-embed-fix.php';")) {
    fwrite(STDERR, "product video embed fix is not loaded by head analytics\n");
    exit(1);
}

$requiredSnippets = [
    'strict-origin-when-cross-origin',
    "embedUrl.searchParams.set('playsinline', '1')",
    "embedUrl.searchParams.set('origin', window.location.origin)",
    "iframe.referrerPolicy = 'strict-origin-when-cross-origin'",
    ".thumb-btn[data-type=\"video\"]",
];

foreach ($requiredSnippets as $snippet) {
    if (!is_string($fix) || !str_contains($fix, $snippet)) {
        fwrite(STDERR, "missing product video embed hardening: {$snippet}\n");
        exit(1);
    }
}

echo "PRODUCT_VIDEO_EMBED_FIX_OK\n";
