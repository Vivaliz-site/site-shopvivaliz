<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$helper = $root . '/includes/olist-erp-webhook.php';
if (!is_file($helper)) {
    fwrite(STDERR, "olist-erp-webhook helper missing\n");
    exit(1);
}
require_once $helper;

function t_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function t_items(string $path): array
{
    $payload = json_decode((string)file_get_contents($path), true);
    if (!is_array($payload)) return [];
    if (array_is_list($payload)) return $payload;
    return is_array($payload['itens'] ?? null) ? $payload['itens'] : [];
}

function t_find(array $items, string $sku): array
{
    foreach ($items as $item) {
        if (is_array($item) && strcasecmp((string)($item['sku'] ?? ''), $sku) === 0) return $item;
    }
    return [];
}

$tmp = sys_get_temp_dir() . '/sv-olist-' . bin2hex(random_bytes(5));
mkdir($tmp . '/storage/cache/catalog-api', 0775, true);
mkdir($tmp . '/api/catalog', 0775, true);
$seed = [
    'id' => 1,
    'sku' => 'OLD-1',
    'descricao' => 'Produto antigo',
    'situacao' => 'A',
    'precos' => ['preco' => 10.0, 'precoPromocional' => 0.0],
    'estoque' => ['quantidade' => 2],
    'estoque_disponivel' => 2,
    'sync_source' => 'tiny_v3',
];
file_put_contents(
    $tmp . '/storage/products-cache-ativos.json',
    json_encode(['success' => true, 'itens' => [$seed], 'items' => [$seed]], JSON_UNESCAPED_UNICODE)
);
file_put_contents(
    $tmp . '/api/catalog/fallback-products.json',
    json_encode([$seed], JSON_UNESCAPED_UNICODE)
);

$detail = [
    'id' => 350339,
    'sku' => '35039',
    'descricao' => 'NIVEL LASER MTX',
    'descricaoComplementar' => '<p>Nivel laser profissional</p>',
    'situacao' => 'A',
    'unidade' => 'PC',
    'gtin' => '7890000000000',
    'precos' => ['preco' => 199.90, 'precoPromocional' => 179.90],
    'categoria' => ['nome' => 'Ferramentas', 'caminhoCompleto' => 'Ferramentas > Medicao'],
    'marca' => ['nome' => 'MTX'],
    'dimensoes' => ['largura' => 15, 'altura' => 12, 'comprimento' => 110, 'pesoLiquido' => 1.5],
    'anexos' => [['url' => 'https://example.test/35039.jpg']],
    'seo' => ['titulo' => 'Nivel Laser MTX', 'descricao' => 'Nivel laser MTX', 'slug' => 'nivel-laser-mtx-35039'],
];
$product = svoei_normalize_v3_product($detail, ['disponivel' => 7]);
t_assert(($product['sku'] ?? '') === '35039', 'SKU v3 nao normalizado');
t_assert(($product['estoque_disponivel'] ?? -1) === 7, 'estoque v3 nao normalizado');
t_assert((float)($product['precos']['preco'] ?? 0) === 199.90, 'preco v3 nao normalizado');
t_assert(($product['sync_source'] ?? '') === 'tiny_v3', 'fonte canonica ausente');

svoei_upsert_catalog_product($tmp, $product);
$active = t_items($tmp . '/storage/products-cache-ativos.json');
t_assert(count($active) === 2, 'upsert deve preservar catalogo ativo existente');
t_assert(t_find($active, '35039') !== [], 'novo produto nao gravado no cache ativo');

svoei_update_catalog_price($tmp, '35039', 209.90, 189.90);
$updated = t_find(t_items($tmp . '/storage/products-cache-ativos.json'), '35039');
t_assert((float)($updated['precos']['preco'] ?? 0) === 209.90, 'preco nao atualizado');
t_assert((float)($updated['precos']['precoPromocional'] ?? 0) === 189.90, 'preco promocional nao atualizado');

svoei_update_catalog_stock($tmp, '35039', 11);
$updated = t_find(t_items($tmp . '/storage/products-cache-ativos.json'), '35039');
t_assert((int)($updated['estoque_disponivel'] ?? -1) === 11, 'estoque disponivel nao atualizado');
t_assert((int)($updated['estoque']['quantidade'] ?? -1) === 11, 'estoque aninhado nao atualizado');

$webhookProduct = [
    'id' => '350339',
    'idMapeamento' => '991',
    'skuMapeamento' => '',
    'codigo' => '35039',
    'nome' => 'NIVEL LASER MTX',
    'anexos' => [['url' => 'https://example.test/35039.jpg']],
    'variacoes' => [[
        'id' => '350340',
        'idMapeamento' => '992',
        'skuMapeamento' => '',
        'codigo' => '35039-AZ',
        'anexos' => [],
    ]],
];
$mappings = svoei_product_mapping_response($webhookProduct, 'https://shopvivaliz.com.br');
t_assert(count($mappings) === 2, 'retorno de produto deve mapear pai e variacoes');
t_assert(($mappings[0]['idMapeamento'] ?? '') === '991', 'idMapeamento pai incorreto');
t_assert(($mappings[0]['skuMapeamento'] ?? '') === '35039', 'skuMapeamento pai incorreto');
t_assert(str_contains((string)($mappings[0]['urlProduto'] ?? ''), '/produto/'), 'urlProduto ausente');
t_assert(($mappings[1]['idMapeamento'] ?? '') === '992', 'idMapeamento variacao incorreto');
t_assert(($mappings[1]['skuMapeamento'] ?? '') === '35039-AZ', 'skuMapeamento variacao incorreto');

$types = svoei_supported_types();
foreach (['produto', 'precos', 'estoque', 'situacao_pedido', 'rastreio', 'nota_fiscal', 'cotacao'] as $type) {
    t_assert(in_array($type, $types, true), "tipo oficial nao suportado: {$type}");
}

$canonical = svoei_order_state_from_v3([
    'pedido' => [
        'situacao' => 5,
        'codigoRastreamento' => 'TRACK123',
        'urlRastreamento' => 'https://carrier.test/track/123',
        'dataPrevista' => '2026-09-15',
        'idNotaFiscal' => 9988,
    ],
]);
t_assert(($canonical['status'] ?? '') === '5', 'situacao v3 nao normalizada');
t_assert(($canonical['tracking'] ?? '') === 'TRACK123', 'rastreio v3 nao normalizado');
t_assert(($canonical['tracking_url'] ?? '') === 'https://carrier.test/track/123', 'url de rastreio v3 nao normalizada');
t_assert(($canonical['estimated_delivery'] ?? '') === '2026-09-15', 'previsao v3 nao normalizada');
t_assert(($canonical['invoice_id'] ?? '') === '9988', 'nota fiscal v3 nao normalizada');

$quotes = svoei_map_shipping_options([
    ['id' => 2, 'name' => 'Expresso', 'price' => '25.90', 'delivery_time' => 2],
    ['id' => 1, 'name' => 'Economico', 'price' => '15.50', 'delivery_time' => 5],
    ['id' => 3, 'error' => 'indisponivel'],
]);
t_assert(count($quotes) === 2, 'cotacoes invalidas devem ser descartadas');
t_assert(($quotes[0]['codigo'] ?? '') === '1', 'cotacoes devem ser ordenadas por preco');
t_assert((float)($quotes[0]['preco'] ?? 0) === 15.50, 'preco de frete incorreto');
t_assert((int)($quotes[0]['prazo'] ?? -1) === 5, 'prazo de frete incorreto');

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $entry) {
    if ($entry->isDir()) rmdir($entry->getPathname());
    else unlink($entry->getPathname());
}
rmdir($tmp);

echo "olist-erp-webhook-contract: ok\n";
