<?php

declare(strict_types=1);

require_once __DIR__ . '/guideline-audit/text.php';
require_once __DIR__ . '/guideline-audit/result.php';
require_once __DIR__ . '/guideline-audit/base-content.php';
require_once __DIR__ . '/guideline-audit/catalog-identity.php';
require_once __DIR__ . '/guideline-audit/catalog-channel-rules.php';
require_once __DIR__ . '/guideline-audit/amazon.php';

/** @param array<string,mixed> $content @param array<string,mixed> $product @return array<string,mixed> */
function sv_marketplace_catalog_guideline_audit(array $content, array $product, string $channel): array
{
    $channel = sv_marketplace_guideline_channel($channel);
    $profile = sv_marketplace_guideline_profile($channel);
    $policy = is_array($profile['catalog'] ?? null) ? $profile['catalog'] : [];
    $data = sv_marketplace_guideline_content($content);
    $checks = [];
    sv_guideline_audit_content($data, $product, $policy, $checks);
    sv_guideline_audit_identity($data, $product, $channel, $policy, $checks);
    sv_guideline_audit_channel_rules($data, $channel, $checks);
    if ($channel === 'amazon') sv_guideline_audit_amazon($data, $product, $policy, $checks);
    return sv_guideline_audit_finish($channel, $profile, $checks);
}
