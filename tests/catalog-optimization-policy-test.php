<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/catalog-optimization/api/optimize_catalog.php';

function cot_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function cot_expect_rejection(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (CatalogAiApiException $e) {
        return;
    }
    fwrite(STDERR, "FAIL: expected rejection - {$message}\n");
    exit(1);
}

function cot_base_data(): array
{
    return [
        'optimized_title' => 'Suporte Articulado Mesa Modelo X1 Preto',
        'optimized_description' => 'Suporte articulado para mesa, modelo X1, na cor preta. Estrutura descrita conforme os dados cadastrados.',
        'bullet_points' => [
            'Modelo X1',
            'Cor preta',
            'Uso em mesa',
        ],
        'seo_keywords' => ['suporte articulado mesa', 'modelo x1'],
        'marketing_hooks' => [],
        'meta_title' => 'Suporte Articulado Mesa X1 Preto',
        'meta_description' => 'Conheca as caracteristicas cadastradas do Suporte Articulado X1 para mesa na cor preta.',
    ];
}

$product = [
    'name' => 'Suporte Articulado Mesa X1 Preto',
    'description' => 'Suporte articulado para mesa. Modelo X1. Cor preta.',
    'category' => 'Suportes',
    'brand' => '',
    'model' => 'X1',
    'sku' => 'SUP-X1-PT',
    'gtin' => '',
    'color' => 'Preto',
    'size' => '',
    'material' => '',
    'specs' => 'Uso em mesa',
    'olist_id' => '123',
];

// Mercado Livre: saida factual dentro do limite deve passar.
$ml = cot_base_data();
ai_catalog_validate_ai_response($ml, 'ml', $product);
$report = ai_catalog_quality_report($ml, 'ml', $product);
cot_assert($report['score'] === 100, 'Mercado Livre factual should score 100');

// Preco/estoque e condicao comercial nunca podem aparecer.
$badCommerce = cot_base_data();
$badCommerce['optimized_description'] .= ' Preco especial com desconto e frete gratis.';
cot_expect_rejection(fn() => ai_catalog_validate_ai_response($badCommerce, 'ml', $product), 'protected commerce fields');

// Claim sem fonte deve ser rejeitado.
$badGuarantee = cot_base_data();
$badGuarantee['optimized_description'] .= ' Com garantia de fabrica.';
cot_expect_rejection(fn() => ai_catalog_validate_ai_response($badGuarantee, 'shopee', $product), 'unsourced guarantee');

// Amazon: exatamente 5 bullets, titulo curto e sem repeticao abusiva.
$amazon = cot_base_data();
$amazon['optimized_title'] = 'Suporte Articulado Mesa X1 Preto';
$amazon['bullet_points'] = [
    'MODELO: X1 conforme cadastro',
    'COR: preta conforme cadastro',
    'TIPO: suporte articulado',
    'USO: indicado para mesa conforme descricao cadastrada',
    'IDENTIFICACAO: SKU SUP-X1-PT',
];
ai_catalog_validate_ai_response($amazon, 'amazon', $product);

$amazonLong = $amazon;
$amazonLong['optimized_title'] = str_repeat('A', 76);
cot_expect_rejection(fn() => ai_catalog_validate_ai_response($amazonLong, 'amazon', $product), 'Amazon title > 75');

$amazonRepeat = $amazon;
$amazonRepeat['optimized_title'] = 'Suporte Suporte Suporte Mesa X1';
cot_expect_rejection(fn() => ai_catalog_validate_ai_response($amazonRepeat, 'amazon', $product), 'Amazon repeated word');

// TikTok Shop: 3 hooks factuais obrigatorios e bullets curtos.
$tiktok = cot_base_data();
$tiktok['marketing_hooks'] = [
    'Veja o modelo X1 em uso sobre a mesa.',
    'Conheca a estrutura articulada cadastrada do X1.',
    'Confira os detalhes do suporte na cor preta.',
];
ai_catalog_validate_ai_response($tiktok, 'tiktok', $product);

$tiktokBad = $tiktok;
$tiktokBad['marketing_hooks'] = ['Apenas um hook factual.'];
cot_expect_rejection(fn() => ai_catalog_validate_ai_response($tiktokBad, 'tiktok', $product), 'TikTok requires 3 hooks');

// ERP: nenhum SEO ou hook de marketing.
$erp = cot_base_data();
$erp['seo_keywords'] = [];
$erp['marketing_hooks'] = [];
ai_catalog_validate_ai_response($erp, 'erp', $product);

$erpBad = $erp;
$erpBad['seo_keywords'] = ['comprar suporte'];
cot_expect_rejection(fn() => ai_catalog_validate_ai_response($erpBad, 'erp', $product), 'ERP must not contain SEO keywords');

// Marca real deve permanecer no titulo de ML/Amazon.
$brandedProduct = $product;
$brandedProduct['brand'] = 'Acme';
$branded = cot_base_data();
$branded['optimized_title'] = 'Suporte Articulado X1 Preto';
cot_expect_rejection(fn() => ai_catalog_validate_ai_response($branded, 'ml', $brandedProduct), 'real brand must be preserved');

// Prompts nao devem receber preco/estoque e devem conter identificadores factuais.
$userPrompt = ai_catalog_build_user_prompt($product, 'ml');
cot_assert(stripos($userPrompt, 'preco') === false, 'user prompt must not contain price field');
cot_assert(stripos($userPrompt, 'estoque') === false, 'user prompt must not contain stock field');
cot_assert(str_contains($userPrompt, 'SUP-X1-PT'), 'user prompt must preserve SKU');
cot_assert(str_contains($userPrompt, 'X1'), 'user prompt must preserve model');

// A regeneracao do Admin historicamente chama o validador sem canal/produto.
// O contexto guardado pela construcao do prompt deve impedir qualquer bypass.
ai_catalog_build_user_prompt($product, 'amazon');
cot_expect_rejection(
    fn() => ai_catalog_validate_ai_response($amazonLong),
    'regeneration must inherit Amazon title policy from request context'
);

ai_catalog_build_user_prompt($product, 'shopee');
cot_expect_rejection(
    fn() => ai_catalog_validate_ai_response($badGuarantee),
    'regeneration must inherit sourced-claim policy from request context'
);

fwrite(STDOUT, "OK catalog optimization marketplace policy\n");
