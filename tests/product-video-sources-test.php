<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/product-video-sources.php';

$registry = [
    '381747419' => [
        'direct_url' => 'https://shopvivaliz.com.br/uploads/v/381747419.mp4',
        'youtube_url' => 'https://youtu.be/abcdefghijk',
    ],
];
$product = ['id' => 381747419, 'sku' => '35039', 'video_url' => 'https://shopvivaliz.com.br/uploads/v/381747419.mp4'];
$merged = sv_product_video_sources_merge($product, $registry);
if (($merged['video_url'] ?? '') !== $product['video_url']) {
    throw new RuntimeException('direct ERP video must be preserved');
}
if (($merged['youtube_url'] ?? '') !== 'https://youtu.be/abcdefghijk') {
    throw new RuntimeException('youtube companion URL must be merged');
}

echo "product-video-sources: ok\n";
