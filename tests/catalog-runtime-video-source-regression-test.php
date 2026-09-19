<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$runtime = (string)file_get_contents($root . '/includes/catalog-runtime.php');
if (!str_contains($runtime, "'youtube_url'")) {
    fwrite(STDERR, "catalog runtime must preserve youtube_url from synchronized cache\n");
    exit(1);
}
$catalogApi = (string)file_get_contents($root . '/api/catalog/products.php');
foreach (["'video_url' =>", "'youtube_url' =>"] as $needle) {
    if (!str_contains($catalogApi, $needle)) {
        fwrite(STDERR, "catalog API must preserve synchronized product video fields: {$needle}\n");
        exit(1);
    }
}

echo "catalog-runtime-video-source-regression: ok\n";
