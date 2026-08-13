<?php

declare(strict_types=1);

/** @return array<string,mixed> */
function sv_guideline_site_profile(): array
{
    return [
        'label' => 'Site Proprio',
        'policy_version' => 'site-google-commerce-2026-08-r2',
        'verified_at' => '2026-08-13',
        'catalog' => [
            'title_max' => 70,
            'title_recommended_min' => 45,
            'title_recommended_max' => 65,
            'bullets_min' => 3,
            'bullets_max' => 5,
            'description_recommended_min' => 180,
            'meta_title_max' => 60,
            'meta_description_max' => 160,
        ],
        'image' => [
            'minimum_side' => 1000,
            'recommended_side' => 1600,
            'max_gallery' => 12,
            'minimum_gallery_recommended' => 3,
            'white_first' => false,
            'strict_white_types' => [],
            'supported_ratio_min' => 0.75,
            'supported_ratio_max' => 1.3334,
        ],
        'official_requirements' => ['Titulo, H1, dados estruturados e produto real devem permanecer consistentes.'],
        'official_recommendations' => ['Metadados descritivos, sem keyword stuffing e sem promessa de ranking.'],
        'operational_targets' => ['Descricao factual e escaneavel.', 'Capa, hero e ambientada com funcoes distintas.'],
        'unverified_advice' => [],
        'references' => [],
    ];
}
