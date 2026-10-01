<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php');
if (!is_string($index) || $index === '') {
    fwrite(STDERR, "FALHOU: index.php ausente ou vazio\n");
    exit(1);
}

function home_merch_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "FALHOU: {$message}\n");
    exit(1);
}

$carouselPos = strpos($index, '<section class="hero-carousel-section homepage-primary-merchandising">');
$searchHeroPos = strpos($index, '<section class="hero home-discovery-hero">');

home_merch_assert($carouselPos !== false, 'Carrossel principal deve ser identificado como merchandising prioritario.');
home_merch_assert($searchHeroPos !== false, 'Bloco de busca deve permanecer como hero de descoberta compacto.');
home_merch_assert($carouselPos < $searchHeroPos, 'Banners devem aparecer antes do bloco de busca na home.');

home_merch_assert(str_contains($index, "'tag' => 'DESCONTO AUTOMÁTICO'"), 'Primeiro banner deve comunicar o desconto de forma direta.');
home_merch_assert(str_contains($index, "'title' => 'Leve mais e pague menos.'"), 'Primeiro banner deve usar beneficio comercial claro.');
home_merch_assert(str_contains($index, "'primary' => ['label' => 'Aproveitar 3% OFF'"), 'CTA do desconto deve dizer o que o cliente recebe.');

home_merch_assert(str_contains($index, "'tag' => 'CASA, OFICINA E NEGÓCIO'"), 'Segundo banner deve representar a amplitude real do catalogo.');
home_merch_assert(str_contains($index, "'title' => 'Do reparo à organização, encontre o que precisa.'"), 'Segundo banner deve explicar utilidade sem linguagem generica de colecao.');
home_merch_assert(str_contains($index, "'primary' => ['label' => 'Explorar produtos'"), 'Segundo banner deve usar CTA orientado a compra.');

home_merch_assert(str_contains($index, '<h1>Procure, compare e escolha <span class="gradient-word">com facilidade</span></h1>'), 'H1 deve apoiar descoberta de produtos, sem repetir o banner.');
home_merch_assert(str_contains($index, 'Busque por produto, marca ou categoria e confira preço, disponibilidade e frete antes de finalizar.'), 'Hero de busca deve explicar a proxima acao com informacao verificavel.');
home_merch_assert(str_contains($index, 'placeholder="Busque por produto, marca ou categoria"'), 'Busca deve ter placeholder curto e orientado a tarefa.');

foreach ([
    'Seu projeto começa com',
    'COLEÇÃO 2026',
    'Renove o seu espaço.',
    'Ferramentas de alta precisão e organização inteligente para uma casa impecável.',
    'OFERTA EXCLUSIVA',
    'Tudo o que você precisa.',
] as $oldCopy) {
    home_merch_assert(!str_contains($index, $oldCopy), "Copy antiga/generica deve ser removida: {$oldCopy}");
}

fwrite(STDOUT, "COMPROVADO: banners lideram a home e copy/CTAs refletem oferta e catalogo reais.\n");
