<?php

declare(strict_types=1);

/** @return array<string,mixed> */
function sv_guideline_erp_profile(): array
{
    return [
        'label' => 'Olist / Tiny ERP',
        'policy_version' => 'tiny-v3-catalog-2026-08-r2',
        'verified_at' => '2026-08-13',
        'catalog' => [
            'title_max' => 120,
            'bullets_min' => 0,
            'bullets_max' => 8,
            'description_recommended_min' => 0,
            'meta_title_max' => 70,
            'meta_description_max' => 160,
        ],
        'image' => [
            'minimum_side' => 1000,
            'recommended_side' => 1200,
            'max_gallery' => 0,
            'minimum_gallery_recommended' => 0,
            'white_first' => false,
            'strict_white_types' => [],
            'supported_ratio_min' => 0.75,
            'supported_ratio_max' => 1.3334,
        ],
        'official_requirements' => [
            'ERP permanece fonte tecnica de SKU, GTIN, marca, categoria e identificadores.',
            'Imagem gerada permanece bloqueada quando a API exigiria reenviar preco.',
        ],
        'official_recommendations' => [],
        'operational_targets' => [
            'Nomenclatura tecnica sem hooks, hashtags ou linguagem promocional.',
            'Preco e estoque nao sao lidos nem alterados pela rotina.',
        ],
        'unverified_advice' => [],
        'references' => [],
    ];
}
