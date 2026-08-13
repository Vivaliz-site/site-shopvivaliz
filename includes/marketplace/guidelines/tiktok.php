<?php

declare(strict_types=1);

/** @return array<string,mixed> */
function sv_guideline_tiktok_profile(): array
{
    return [
        'label' => 'TikTok Shop',
        'policy_version' => 'tiktok-shop-br-2026-08-r2',
        'verified_at' => '2026-08-13',
        'catalog' => [
            'title_min' => 1,
            'title_max' => 300,
            'title_recommended_min' => 40,
            'title_recommended_max' => 150,
            'bullets_min' => 3,
            'bullets_max' => 5,
            'bullet_max' => 249,
            'description_max' => 10000,
            'description_recommended_min' => 500,
            'meta_title_max' => 70,
            'meta_description_max' => 160,
        ],
        'image' => [
            'minimum_side' => 1000,
            'recommended_side' => 1600,
            'api_minimum_side' => 300,
            'api_maximum_side' => 4000,
            'max_gallery' => 9,
            'minimum_gallery_recommended' => 5,
            'white_first' => true,
            'strict_white_types' => ['white', 'cover'],
            'supported_ratio_min' => 0.75,
            'supported_ratio_max' => 1.3334,
        ],
        'official_requirements' => [
            'No Brasil, titulo entre 1 e 300 caracteres e descricao de ate 10.000.',
            'Galeria com no maximo 9 imagens entre 300 e 4000 pixels.',
            'Categoria, brand_id e atributos devem corresponder ao produto real.',
        ],
        'official_recommendations' => [
            'Descricao detalhada acima de 300 caracteres; diagnostico regional recomenda 500.',
            'Usar 3 a 5 selling points abaixo de 250 caracteres.',
            'Usar pelo menos 5 imagens, alvo 1600x1600 e primeira imagem branca.',
        ],
        'operational_targets' => [
            'Titulo entre 40 e 150 caracteres quando houver fatos suficientes.',
            'Hero e ambientada envolventes sem urgencia, resultado ou compatibilidade inventada.',
        ],
        'unverified_advice' => [
            'Nao impor titulo de 30 a 50 caracteres como regra tecnica.',
            'Nao inserir tendencia, audio ou hashtag sem evidencia e revisao humana.',
            'Comissao de afiliado nao pertence a esta rotina.',
        ],
        'references' => [
            ['label' => 'Partial Edit Product API', 'url' => 'https://partner.tiktokshop.com/docv2/page/partial-edit-product'],
            ['label' => 'Listing Quality Diagnosis', 'url' => 'https://partner.tiktokshop.com/docv2/page/listing-quality-diagnosis'],
        ],
    ];
}
