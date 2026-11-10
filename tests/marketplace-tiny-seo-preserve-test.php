<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/marketplace/TinyPublisher.php';

function assertSeo(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$before = [
    'titulo' => 'Titulo original',
    'descricao' => 'Descricao original',
    'keywords' => ['original', 'historico'],
    'slug' => 'sku-original',
    'linkVideo' => 'https://example.test/video',
];
$noProposal = SvTinyPublisher::mergeSeo($before, [
    'meta_title' => '',
    'meta_description' => '',
    'seo_keywords' => [],
]);
assertSeo($noProposal === $before, 'A proposta vazia apagou o SEO historico.');

$proposal = SvTinyPublisher::mergeSeo($before, [
    'meta_title' => 'Titulo revisado',
    'meta_description' => '',
    'seo_keywords' => [' ', 'nova palavra ', 'catalogo'],
]);
assertSeo($proposal['titulo'] === 'Titulo revisado', 'Titulo novo nao publicado.');
assertSeo($proposal['descricao'] === 'Descricao original', 'Descricao antiga perdida.');
assertSeo($proposal['keywords'] === ['nova palavra', 'catalogo'], 'Keywords propostas incorretas.');
assertSeo($proposal['slug'] === 'sku-original', 'Slug foi apagado.');
assertSeo($proposal['linkVideo'] === 'https://example.test/video', 'Video foi apagado.');

$withNoSeo = SvTinyPublisher::mergeSeo([], [
    'meta_title' => '',
    'meta_description' => '',
    'seo_keywords' => [],
]);
assertSeo($withNoSeo === [], 'Proposta sem SEO nao deve criar campos artificiais.');
// Regressao forense: o SKU foi reaproveitado depois de excluir o ID antigo.
$liveId = SvTinyPublisher::activeIdFromRows('TTH-K*BCO-01', [
    ['id' => 380956865, 'sku' => 'TTH-K*BCO-01'],
]);
assertSeo($liveId === 380956865, 'Resolver identificou o ID historico excluido.');

$failed = false;
try {
    SvTinyPublisher::activeIdFromRows('TTH-K*BCO-01', [
        ['id' => 380956865, 'sku' => 'TTH-K*BCO-01'],
        ['id' => 360774093, 'sku' => 'TTH-K*BCO-01'],
    ]);
} catch (RuntimeException $e) {
    $failed = true;
}
assertSeo($failed, 'IDs ambiguos com mesmo SKU nao podem ser usados para escrita.');

$failed = false;
try {
    SvTinyPublisher::activeIdFromRows('SKU-ATIVO', [
        ['id' => 10, 'sku' => 'SKU-ATIVO-NAO-EXATO'],
    ]);
} catch (RuntimeException $e) {
    $failed = true;
}
assertSeo($failed, 'Busca textual por SKU parcial nao pode selecionar o registro.');

echo "marketplace-tiny-seo-preserve: ok\n";
