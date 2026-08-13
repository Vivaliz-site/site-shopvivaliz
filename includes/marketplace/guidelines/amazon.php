<?php

declare(strict_types=1);

/** @return array<string,mixed> */
function sv_guideline_amazon_profile(): array
{
    return [
        'label' => 'Amazon',
        'policy_version' => 'amazon-listings-items-2026-08-r2',
        'verified_at' => '2026-08-13',
        'catalog' => [
            'title_max' => 200,
            'bullets_min' => 5,
            'bullets_max' => 5,
            'description_recommended_min' => 180,
            'search_terms_max_bytes' => 249,
            'meta_title_max' => 70,
            'meta_description_max' => 160,
            'title_word_repeat_max' => 2,
            'title_disallowed_chars' => '!$?_{}^¬¦',
        ],
        'image' => [
            'minimum_side' => 1000,
            'recommended_side' => 1600,
            'max_gallery' => 9,
            'minimum_gallery_recommended' => 5,
            'white_first' => true,
            'strict_white_types' => ['white', 'cover'],
            'supported_ratio_min' => 0.75,
            'supported_ratio_max' => 1.3334,
        ],
        'official_requirements' => [
            'Na maioria das categorias, titulo com no maximo 200 caracteres.',
            'Titulo sem caracteres proibidos e sem repetir palavra relevante mais de duas vezes.',
            'Generic keywords abaixo de 250 bytes; alvo seguro de 249 bytes.',
            'Product Type Definitions determinam atributos validos e obrigatorios.',
        ],
        'official_recommendations' => [
            'Search terms unicos, separados por espaco e sem repetir titulo, marca ou bullets.',
            'Main image branca; imagens secundarias somente com informacao factual.',
        ],
        'operational_targets' => [
            'Exatamente 5 bullets factuais.',
            'Descricao tecnica; marketing hooks permanecem internos.',
        ],
        'unverified_advice' => [
            'Nao alterar preco ou estoque para tentar influenciar ranking.',
            'Nao tratar A9 ou A10 como contrato tecnico verificavel.',
            'Nao prometer melhora automatica por A+ ou texto.',
        ],
        'references' => [
            ['label' => 'Requisitos de titulo', 'url' => 'https://sellercentral.amazon.com/seller-forums/discussions/t/b2b15728-0d43-453e-974f-59eb63f73059'],
            ['label' => 'Boas praticas de search terms', 'url' => 'https://sellercentral.amazon.com/seller-forums/discussions/t/62e0e226-97ab-4d3d-8e87-c93e02dd6826'],
        ],
    ];
}
