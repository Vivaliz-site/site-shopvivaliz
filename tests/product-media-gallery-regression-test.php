<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$mediaHelper = $root . '/includes/product-media.php';
$failures = [];

if (!is_file($mediaHelper)) {
    $failures[] = 'includes/product-media.php must exist';
} else {
    require_once $mediaHelper;
    if (!function_exists('sv_product_video_media')) {
        $failures[] = 'sv_product_video_media() must exist';
    } else {
        $mp4 = sv_product_video_media('https://shopvivaliz.com.br/uploads/v/381747419.mp4');
        if (($mp4['type'] ?? '') !== 'video' || ($mp4['src'] ?? '') !== 'https://shopvivaliz.com.br/uploads/v/381747419.mp4') {
            $failures[] = 'direct MP4 URLs must be rendered as playable video media';
        }
        $youtube = sv_product_video_media('https://youtu.be/dQw4w9WgXcQ');
        if (($youtube['type'] ?? '') !== 'iframe' || !str_contains((string)($youtube['src'] ?? ''), 'youtube.com/embed/dQw4w9WgXcQ')) {
            $failures[] = 'YouTube URLs must remain iframe embeds';
        }
        if (!function_exists('sv_product_video_choice')) {
            $failures[] = 'sv_product_video_choice() must exist';
        } else {
            $choice = sv_product_video_choice(['video_url' => 'https://shopvivaliz.com.br/a.mp4', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ']);
            if (($choice['src'] ?? '') !== 'https://shopvivaliz.com.br/a.mp4') $failures[] = 'direct video must win over YouTube';
            $fallback = sv_product_video_choice(['video_url' => '', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ']);
            if (($fallback['type'] ?? '') !== 'iframe') $failures[] = 'YouTube must be used when direct video is absent';
        }
    }
}

$productPage = (string)file_get_contents($root . '/produto.php');
if (!str_contains($productPage, '/css/product-conversion-v5.css?v=2026-09-13-media1')) {
    $failures[] = 'product media CSS must use a fresh cache-busting version';
}
if (!str_contains($productPage, 'data-video-kind') || !str_contains($productPage, "document.createElement('video')")) {
    $failures[] = 'product gallery must render direct video files with an HTML5 video element';
}

$css = (string)file_get_contents($root . '/css/product-conversion-v5.css');
if (!preg_match('/\.product-detail-image img\s*\{[^}]*width:\s*100%\s*!important;[^}]*height:\s*100%\s*!important;/s', $css)) {
    $failures[] = 'main product image must fill the gallery viewport instead of staying at intrinsic size';
}

if ($failures !== []) {
    fwrite(STDERR, "FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "PASS product media gallery regression\n";
