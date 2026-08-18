<?php

declare(strict_types=1);

/**
 * Authoritative list of production agents.
 *
 * Every entry is available through the production watchdog. The watchdog
 * executes one evidence-backed cycle per hour. Agent.php files in the
 * versioned production package (agents/v9.2.84) that are not present here are
 * rejected by the regression gate,
 * preventing dormant, simulated or duplicate agents from being advertised as
 * production workers. The aggregate cycle embeds the complete result of every
 * agent and is the authoritative evidence consumed by CI and the admin panel.
 */
function svpa_registry(): array
{
    return [
        'schema_version' => 2,
        'trigger_mode' => 'scheduled',
        'schedule_minutes' => 60,
        'workflow' => '.github/workflows/autonomous-watchdog.yml',
        'aggregate_evidence' => 'storage/agent-evidence/latest-agent-cycle.json',
        'agents' => [
            'operational_work' => [
                'class' => 'ShopvivalizRealWorkOrchestratorAgent',
                'file' => 'agents/v9.2.84/app/RealWorkOrchestratorAgent.php',
                'mode' => 'mutating',
            ],
            'catalog_metadata' => [
                'class' => 'ShopvivalizCatalogMetadataAgent',
                'file' => 'agents/v9.2.84/app/CatalogMetadataAgent.php',
                'mode' => 'mutating',
            ],
            'conversion_funnel' => [
                'class' => 'ShopvivalizConversionFunnelAgent',
                'file' => 'agents/v9.2.84/app/ConversionFunnelAgent.php',
                'mode' => 'observability',
            ],
            'media_quality' => [
                'class' => 'ShopvivalizMediaQualityAgent',
                'file' => 'agents/v9.2.84/app/MediaQualityAgent.php',
                'mode' => 'observability',
            ],
            'media_mismatch' => [
                'class' => 'ShopvivalizMediaMismatchAgent',
                'file' => 'agents/v9.2.84/app/MediaMismatchAgent.php',
                'mode' => 'observability',
            ],
        ],
    ];
}

function svpa_registry_hash(array $registry): string
{
    $json = json_encode($registry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return hash('sha256', $json);
}
