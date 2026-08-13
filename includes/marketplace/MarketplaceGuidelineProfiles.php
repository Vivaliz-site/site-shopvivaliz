<?php

declare(strict_types=1);

require_once __DIR__ . '/guidelines/site.php';
require_once __DIR__ . '/guidelines/mercado-livre.php';
require_once __DIR__ . '/guidelines/shopee.php';
require_once __DIR__ . '/guidelines/amazon.php';
require_once __DIR__ . '/guidelines/tiktok.php';
require_once __DIR__ . '/guidelines/erp.php';

/** @return array<string,array<string,mixed>> */
function sv_marketplace_guideline_profiles(): array
{
    return [
        'site' => sv_guideline_site_profile(),
        'ml' => sv_guideline_ml_profile(),
        'shopee' => sv_guideline_shopee_profile(),
        'amazon' => sv_guideline_amazon_profile(),
        'tiktok' => sv_guideline_tiktok_profile(),
        'erp' => sv_guideline_erp_profile(),
    ];
}

function sv_marketplace_guideline_channel(string $channel): string
{
    $channel = strtolower(trim($channel));
    return array_key_exists($channel, sv_marketplace_guideline_profiles()) ? $channel : 'site';
}

/** @return array<string,mixed> */
function sv_marketplace_guideline_profile(string $channel): array
{
    $profiles = sv_marketplace_guideline_profiles();
    return $profiles[sv_marketplace_guideline_channel($channel)];
}

/** @return array<string,mixed> */
function sv_marketplace_guideline_public_profile(string $channel): array
{
    $profile = sv_marketplace_guideline_profile($channel);
    return array_merge($profile, [
        'channel' => sv_marketplace_guideline_channel($channel),
        'protected_scope' => ['price', 'stock', 'orders', 'billing', 'campaign_budget'],
    ]);
}
