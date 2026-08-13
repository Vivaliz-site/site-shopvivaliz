<?php

declare(strict_types=1);

/** @return array<string,mixed> */
function sv_guideline_shopee_profile(): array
{
    return [
        'label' => 'Shopee',
        'policy_version' => 'shopee-open-platform-2026-08-r2',
        'verified_at' => '2026-08-13',
        'catalog' => [
            'title_max' => 120,
            'bullets_min' => 3,
            'bullets_max' => 5,
            'description_recommended_min' => 180,
            'meta_title_max' => 70,
            'meta_description_max' => 160,
        ],
        'image' => [
            'minimum_side' => 1000,
            'recommended_side' => 1200,
            'max_gallery' => 9,
            'minimum_gallery_recommended' => 4,
            'white_first' => true,
            'strict_white_types' => ['white'],
            'supported_ratio_min' => 0.75,
            'supported_ratio_max' => 1.3334,
        ],
        'official_requirements' => [
            'Categoria, marca, atributos, variacoes e item real devem permanecer consistentes.',
            'Claims e marcas de terceiros nao podem substituir dados factuais.',
        ],
        'official_recommendations' => [
            'Titulo pesquisavel e legivel no mobile, sem repeticao artificial.',
            'Variacoes claras e coerentes com as imagens correspondentes.',
        ],
        'operational_targets' => [
            'Descricao em blocos curtos com 3 a 5 pontos de decisao.',
            'Capa para miniatura e white separada como referencia tecnica.',
        ],
        'unverified_advice' => [
            'Nao transformar disponibilidade ou promocao em regra editorial.',
            'Nao impor quantidade fixa de hashtags.',
            'Nao iniciar campanha paga nesta rotina.',
        ],
        'references' => [],
    ];
}
