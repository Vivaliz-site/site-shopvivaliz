<?php

declare(strict_types=1);

/** @return array<string,mixed> */
function sv_guideline_ml_profile(): array
{
    return [
        'label' => 'Mercado Livre',
        'policy_version' => 'ml-user-products-2026-08-r2',
        'verified_at' => '2026-08-13',
        'catalog' => [
            'title_max' => 60,
            'bullets_min' => 3,
            'bullets_max' => 5,
            'description_recommended_min' => 140,
            'meta_title_max' => 70,
            'meta_description_max' => 160,
        ],
        'image' => [
            'minimum_side' => 1000,
            'recommended_side' => 1200,
            'max_gallery' => 12,
            'minimum_gallery_recommended' => 4,
            'white_first' => true,
            'strict_white_types' => ['white', 'cover'],
            'supported_ratio_min' => 0.75,
            'supported_ratio_max' => 1.3334,
        ],
        'official_requirements' => [
            'Usar max_title_length da categoria ou dominio; 60 caracteres e fallback conservador.',
            'Anuncio com vendas nao permite alterar titulo pelo fluxo legado.',
            'Categoria, atributos, GTIN/MPN, variacoes e familia do User Product sao prioritarios.',
        ],
        'official_recommendations' => [
            'Titulo direto com produto, marca, modelo e especificacao identificadora.',
            'Imagem principal limpa, sem texto, borda, selo, marca d agua ou acessorio nao incluso.',
        ],
        'operational_targets' => [
            'Descricao mobile-first com 3 a 5 fatos complementares.',
            'Keywords permanecem internas porque o publisher nao envia esse campo.',
        ],
        'unverified_advice' => [
            'Nao assumir perda automatica de ranking por qualquer edicao.',
            'Nao inferir elegibilidade para Meli+ ou campanhas somente pelo GTIN.',
        ],
        'references' => [
            ['label' => 'Sincronizacao de publicacoes', 'url' => 'https://developers.mercadolivre.com.br/pt_br/produto-consulta-de-usuarios/produto-sincronizacao-de-publicacoes'],
            ['label' => 'User Products', 'url' => 'https://developers.mercadolivre.com.br/pt_br/envio-personalizado/user-products'],
        ],
    ];
}
