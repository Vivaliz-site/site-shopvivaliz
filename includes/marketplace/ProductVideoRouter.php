<?php
declare(strict_types=1);

final class ProductVideoRouter
{
    public static function route(string $channel, array $sources): array
    {
        $channel = strtolower(trim($channel));
        $direct = trim((string)($sources['video_url'] ?? $sources['direct_url'] ?? ''));
        $youtube = trim((string)($sources['youtube_url'] ?? ''));

        if ($channel === 'site') {
            $source = $direct !== '' ? $direct : $youtube;
            return ['supported' => $source !== '', 'mode' => $direct !== '' ? 'direct' : 'youtube_embed', 'source' => $source];
        }
        if ($channel === 'youtube') {
            return ['supported' => $direct !== '', 'mode' => 'upload_direct', 'source' => $direct, 'reason' => $direct !== '' ? '' : 'direct_video_missing'];
        }
        if ($channel === 'tiktok') {
            return ['supported' => $direct !== '', 'mode' => 'direct_upload', 'source' => $direct, 'publisher_ready' => false, 'reason' => $direct !== '' ? 'publisher_video_not_implemented' : 'direct_video_missing'];
        }
        if ($channel === 'ml') {
            return ['supported' => false, 'mode' => 'clips', 'source' => $direct, 'reason' => 'clips_integration_required'];
        }
        if (in_array($channel, ['shopee', 'amazon'], true)) {
            return ['supported' => false, 'mode' => 'none', 'source' => $direct, 'reason' => 'publisher_video_not_implemented'];
        }
        return ['supported' => false, 'mode' => 'none', 'source' => '', 'reason' => 'unknown_channel'];
    }
}
