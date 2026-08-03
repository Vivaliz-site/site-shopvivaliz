<?php

declare(strict_types=1);

/**
 * Authoritative list of production agents.
 *
 * Every entry is executed by the scheduled production watchdog. Files ending
 * in Agent.php that are not present here are not allowed by the regression
 * gate, preventing dormant, simulated or duplicate agents from being
 * advertised as 24/7 workers.
 */
function svpa_registry(): array
{
    return [
        'schema_version' => 1,
        'schedule_minutes' => 15,
        'workflow' => '.github/workflows/autonomous-watchdog.yml',
        'agents' => [
            'operational_work' => [
                'class' => 'ShopvivalizRealWorkOrchestratorAgent',
                'file' => 'agents/v9.2.84/app/RealWorkOrchestratorAgent.php',
                'mode' => 'mutating',
                'evidence' => 'storage/agent-evidence/latest-real-work.json',
            ],
            'catalog_metadata' => [
                'class' => 'ShopvivalizCatalogMetadataAgent',
                'file' => 'agents/v9.2.84/app/CatalogMetadataAgent.php',
                'mode' => 'mutating',
                'evidence' => 'storage/agent-evidence/latest-catalog-metadata.json',
            ],
            'conversion_funnel' => [
                'class' => 'ShopvivalizConversionFunnelAgent',
                'file' => 'agents/v9.2.84/app/ConversionFunnelAgent.php',
                'mode' => 'observability',
                'evidence' => 'storage/agent-evidence/latest-conversion-funnel.json',
            ],
            'media_quality' => [
                'class' => 'ShopvivalizMediaQualityAgent',
                'file' => 'agents/v9.2.84/app/MediaQualityAgent.php',
                'mode' => 'observability',
                'evidence' => 'storage/agent-evidence/latest-media-quality.json',
            ],
            'media_mismatch' => [
                'class' => 'ShopvivalizMediaMismatchAgent',
                'file' => 'agents/v9.2.84/app/MediaMismatchAgent.php',
                'mode' => 'observability',
                'evidence' => 'storage/agent-evidence/latest-media-mismatch.json',
            ],
        ],
    ];
}

function svpa_registry_hash(array $registry): string
{
    $json = json_encode($registry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return hash('sha256', $json);
}
