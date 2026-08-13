<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/marketplace/CatalogChannelProfile.php';
require_once dirname(__DIR__) . '/includes/marketplace/CatalogChangeSet.php';

function change_set_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

$before = [
    'title' => 'Suporte Vivaliz VX10',
    'description' => 'Suporte veicular com fixacao no painel.',
    'bullet_points' => ['Fixacao no painel', 'Cor preta', 'Modelo VX10'],
    'seo_keywords' => ['suporte veicular', 'vx10'],
    'marketing_hooks' => [],
    'meta_title' => '',
    'meta_description' => '',
];

$after = [
    'optimized_title' => 'Suporte Vivaliz VX10 Preto',
    'optimized_description' => 'Suporte veicular com fixacao no painel.',
    'bullet_points' => ['Modelo VX10', 'Cor preta', 'Fixacao firme no painel'],
    'seo_keywords' => ['suporte automotivo', 'vx10'],
    'marketing_hooks' => ['Fixacao pratica no painel'],
    'meta_title' => 'Suporte Vivaliz VX10',
    'meta_description' => 'Suporte veicular Vivaliz VX10 para fixacao no painel.',
];

$mlProfile = sv_catalog_channel_profile('ml');
$ml = sv_catalog_change_set($before, $after, (array)$mlProfile['field_map']);
$mlEffective = array_column((array)$ml['effective'], 'field');
$mlInternal = array_column((array)$ml['internal'], 'field');

change_set_assert(in_array('optimized_title', $mlEffective, true), 'Mercado Livre deve considerar titulo alterado como mudanca efetiva.');
change_set_assert(in_array('bullet_points', $mlEffective, true), 'Bullets incorporados na descricao devem ser mudanca efetiva.');
change_set_assert(!in_array('optimized_description', $mlEffective, true), 'Descricao identica nao pode aparecer como mudanca.');
change_set_assert(in_array('seo_keywords', $mlInternal, true), 'Keywords do ML sao apoio interno e nao podem aparecer como campo publicado.');
change_set_assert(in_array('meta_title', $mlInternal, true), 'Meta title do ML deve permanecer interno.');
change_set_assert((int)$ml['counts']['effective'] === 2, 'ML deve ter exatamente duas mudancas efetivas neste fixture.');

$siteProfile = sv_catalog_channel_profile('site');
$site = sv_catalog_change_set($before, $after, (array)$siteProfile['field_map']);
$siteEffective = array_column((array)$site['effective'], 'field');
change_set_assert(in_array('seo_keywords', $siteEffective, true), 'Keywords do site sao efetivas no conteudo do canal.');
change_set_assert(in_array('meta_title', $siteEffective, true), 'Meta title do site deve ser efetivo.');
change_set_assert(in_array('marketing_hooks', $siteEffective, true), 'Hooks incorporados na vitrine devem ser efetivos.');

$reordered = $before;
$reordered['bullet_points'] = array_reverse($before['bullet_points']);
$sameList = sv_catalog_change_set($before, $reordered, (array)$siteProfile['field_map']);
change_set_assert(!in_array('bullet_points', array_column((array)$sameList['effective'], 'field'), true), 'Reordenacao sem alteracao semantica nao deve gerar falso positivo.');

foreach ((array)$site['all'] as $row) {
    change_set_assert(!in_array((string)($row['field'] ?? ''), ['price', 'stock', 'preco', 'estoque'], true), 'Preco e estoque nunca podem fazer parte do change set editorial.');
}

fwrite(STDOUT, "COMPROVADO: change set separa campos efetivos, internos e inalterados sem tocar preco/estoque.\n");
