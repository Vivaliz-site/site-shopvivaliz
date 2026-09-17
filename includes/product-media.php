<?php
declare(strict_types=1);

/**
 * Normalize a product video URL for the storefront gallery.
 * Returns ['type' => 'iframe'|'video', 'src' => string] or [] when unsupported.
 */
function sv_product_video_media(string $videoUrl): array
{
    $videoUrl = trim($videoUrl);
    if ($videoUrl === '' || !preg_match('~^https?://~i', $videoUrl)) {
        return [];
    }

    if (preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i', $videoUrl, $match)
        || preg_match('%youtube\.com/shorts/([^"&?/ ]{11})%i', $videoUrl, $match)) {
        return [
            'type' => 'iframe',
            'src' => 'https://www.youtube.com/embed/' . $match[1] . '?autoplay=1&rel=0',
        ];
    }

    $path = strtolower((string)parse_url($videoUrl, PHP_URL_PATH));
    if (preg_match('/\.(mp4|webm|ogg|ogv|mov|m4v)$/i', $path)) {
        return ['type' => 'video', 'src' => $videoUrl];
    }

    return [];
}

function sv_product_video_choice(array $product): array
{
    foreach (['video_url', 'youtube_url'] as $field) {
        $url = trim((string)($product[$field] ?? ''));
        if ($url === '') continue;
        $media = sv_product_video_media($url);
        if ($media !== []) return $media + ['source_field' => $field];
    }
    return [];
}
