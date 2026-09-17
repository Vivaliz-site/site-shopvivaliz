<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/marketplace/ProductVideoRouter.php';

$sources = [
    'video_url' => 'https://shopvivaliz.com.br/uploads/v/381747419.mp4',
    'youtube_url' => 'https://youtu.be/AbCdEfGhI12',
];

$site = ProductVideoRouter::route('site', $sources);
if (($site['source'] ?? '') !== $sources['video_url']) throw new RuntimeException('site must prefer direct video');
$youtube = ProductVideoRouter::route('youtube', $sources);
if (($youtube['mode'] ?? '') !== 'upload_direct' || ($youtube['source'] ?? '') !== $sources['video_url']) throw new RuntimeException('YouTube must upload direct source');
$ml = ProductVideoRouter::route('ml', $sources);
if (($ml['reason'] ?? '') !== 'clips_integration_required') throw new RuntimeException('Mercado Livre must not use YouTube legacy video');
$tiktok = ProductVideoRouter::route('tiktok', $sources);
if (($tiktok['source'] ?? '') !== $sources['video_url']) throw new RuntimeException('TikTok must use direct file source');

echo "marketplace-product-video-router: ok\n";
