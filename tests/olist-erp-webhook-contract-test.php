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
$product = svoei_normalize_v3_product($detail, ['disponivel' => 7], [
    'preco' => 199.90,
    'precoPromocional' => 179.90,
    'pdv_list_id' => 12345,
    'price_source' => 'olist_pdv',
]);
t_assert(($product['sku'] ?? '') === '35039', 'SKU v3 nao normalizado');
t_assert(($product['estoque_disponivel'] ?? -1) === 7, 'estoque v3 nao normalizado');
t_assert((float)($product['precos']['preco'] ?? 0) === 199.90, 'preco v3 nao normalizado');
t_assert(($product['sync_source'] ?? '') === 'tiny_v3', 'fonte canonica ausente');

svoei_upsert_catalog_product($tmp, $product);
$active = t_items($tmp . '/storage/products-cache-ativos.json');
t_assert(count($active) === 2, 'upsert deve preservar catalogo ativo existente');
t_assert(t_find($active, '35039') !== [], 'novo produto nao gravado no cache ativo');

svoei_update_catalog_price($tmp, '35039', 209.90, 189.90, '', 12345);
$updated = t_find(t_items($tmp . '/storage/products-cache-ativos.json'), '35039');
t_assert((float)($updated['precos']['preco'] ?? 0) === 209.90, 'preco nao atualizado');
t_assert((float)($updated['precos']['precoPromocional'] ?? 0) === 189.90, 'preco promocional nao atualizado');


$priceRef = svoei_price_event_reference(['idProduto' => '350339']);
t_assert($priceRef['product_id'] === '350339' && $priceRef['sku'] === '', 'id-only price webhook must resolve');
$priceRefBySku = svoei_price_event_reference(['codigo' => '35039']);
t_assert($priceRefBySku['product_id'] === '' && $priceRefBySku['sku'] === '35039', 'sku-only price webhook must resolve');
$priceRefCombined = svoei_price_event_reference(['idProduto' => '350339', 'codigo' => '35039']);
t_assert($priceRefCombined['product_id'] === '350339' && $priceRefCombined['sku'] === '35039', 'combined price webhook references');
$invalidReferenceRejected = false;
try {
    svoei_price_event_reference(['idMapeamento' => '9999']);
} catch (InvalidArgumentException $e) {
    $invalidReferenceRejected = $e->getMessage() === 'product_identifier_missing';
}
t_assert($invalidReferenceRejected, 'mapping id must not be mistaken for an ERP product id');

svoei_update_catalog_price($tmp, 'OLD-ERP-SKU', 211.90, 0.0, '350339', 12345);
$updatedById = t_find(t_items($tmp . '/storage/products-cache-ativos.json'), '35039');
t_assert((float)($updatedById['precos']['preco'] ?? 0) === 211.90, 'valid ERP id must update price even with changed SKU');
t_assert((float)($updatedById['precos']['precoPromocional'] ?? -1) === 0.0, 'removed promotion must be reflected');

// Regression: a recycled SKU on another ERP product must not receive the
// authoritative product ID's price, even when it appears first in the cache.
$collisionRoot = $tmp . '/price-id-collision';
mkdir($collisionRoot . '/storage', 0775, true);
$collisionRows = [
    ['id' => '999', 'sku' => '35039', 'precos' => ['preco' => 9.99]],
    ['id' => '350339', 'sku' => 'OLD-35039', 'precos' => ['preco' => 11.00]],
];
file_put_contents($collisionRoot . '/storage/products-cache-ativos.json', json_encode($collisionRows));
svoei_update_catalog_price($collisionRoot, '35039', 301.10, 0.0, '350339', 12345);
$collisionAfter = t_items($collisionRoot . '/storage/products-cache-ativos.json');
t_assert((float)($collisionAfter[0]['precos']['preco'] ?? 0) === 9.99, 'recycled SKU must not mutate another ERP product');
t_assert((float)($collisionAfter[1]['precos']['preco'] ?? 0) === 301.10, 'canonical product ID must win over a colliding SKU');

// The new-product fallback in the price handler calls the shared upsert.
// It must not undo the ID guard by overwriting a different product by SKU.
$upsertConflictRejected = false;
try {
    svoei_upsert_catalog_product($collisionRoot, [
        'id' => '350339',
        'sku' => '35039',
        'precos' => ['preco' => 999.00],
    ]);
} catch (RuntimeException $e) {
    $upsertConflictRejected = $e->getMessage() === 'catalog_sku_identity_conflict';
}
t_assert($upsertConflictRejected, 'upsert must reject a recycled SKU owned by another ERP ID');
$afterRejectedUpsert = t_items($collisionRoot . '/storage/products-cache-ativos.json');
t_assert((float)($afterRejectedUpsert[0]['precos']['preco'] ?? 0) === 9.99, 'rejected upsert must preserve competing product');
t_assert((float)($afterRejectedUpsert[1]['precos']['preco'] ?? 0) === 301.10, 'rejected upsert must preserve canonical product');

// Updating the same canonical ID with a unique new SKU remains supported.
svoei_upsert_catalog_product($collisionRoot, [
    'id' => '350339',
    'sku' => 'NEW-35039',
    'precos' => ['preco' => 301.20],
]);
$afterRename = t_items($collisionRoot . '/storage/products-cache-ativos.json');
t_assert(($afterRename[0]['sku'] ?? '') === '35039', 'unrelated product SKU must not be rewritten');
t_assert(($afterRename[1]['sku'] ?? '') === 'NEW-35039', 'canonical ERP ID should permit a nonconflicting SKU change');
t_assert(count($afterRename) === 2, 'upsert by authoritative ID must not duplicate products');


// Sibling regression: stock was still SKU-first after prices became ID-first.
// The recycled public SKU belongs to ERP ID 999, while 350339 has a renamed
// SKU. An official stock notification for 350339 must NEVER touch ID 999.
svoei_update_catalog_stock($collisionRoot, '35039', 17, '350339');
$stockById = t_items($collisionRoot . '/storage/products-cache-ativos.json');
t_assert(!isset($stockById[0]['estoque_disponivel']), 'recycled SKU must not receive another product stock');
t_assert((int)($stockById[1]['estoque_disponivel'] ?? -1) === 17, 'stock must follow authoritative ERP ID after SKU rename');
t_assert((int)($stockById[1]['estoque']['quantidade'] ?? -1) === 17, 'nested stock must follow ERP ID');

$wrongStockIdRejected = false;
try {
    svoei_update_catalog_stock($collisionRoot, '35039', 44, '444444');
} catch (RuntimeException $e) {
    $wrongStockIdRejected = $e->getMessage() === 'catalog_product_not_found';
}
t_assert($wrongStockIdRejected, 'stock must reject a SKU owned by another known ERP ID');
$stockAfterWrongId = t_items($collisionRoot . '/storage/products-cache-ativos.json');
t_assert(!isset($stockAfterWrongId[0]['estoque_disponivel']), 'rejected stock must not affect competing product');
t_assert((int)($stockAfterWrongId[1]['estoque_disponivel'] ?? -1) === 17, 'rejected stock must preserve canonical product');

$ambiguousStockRoot = $tmp . '/stock-sku-ambiguous';
mkdir($ambiguousStockRoot . '/storage', 0775, true);
file_put_contents($ambiguousStockRoot . '/storage/products-cache-ativos.json', json_encode([
    ['sku' => 'DUPLICATE-STOCK', 'estoque_disponivel' => 3],
    ['sku' => 'DUPLICATE-STOCK', 'estoque_disponivel' => 7],
]));
$ambiguousStockRejected = false;
try {
    svoei_update_catalog_stock($ambiguousStockRoot, 'DUPLICATE-STOCK', 99);
} catch (RuntimeException $e) {
    $ambiguousStockRejected = $e->getMessage() === 'catalog_product_ambiguous';
}
t_assert($ambiguousStockRejected, 'SKU-only stock must reject ambiguous catalog matches');
$ambiguousStockRows = t_items($ambiguousStockRoot . '/storage/products-cache-ativos.json');
t_assert(($ambiguousStockRows[0]['estoque_disponivel'] ?? null) === 3, 'ambiguous stock must not mutate first row');
t_assert(($ambiguousStockRows[1]['estoque_disponivel'] ?? null) === 7, 'ambiguous stock must not mutate second row');


// Regression: an existing authoritative ERP ID may not take a SKU belonging
// to a separate ID-less legacy row. The old code selected the ID match and
// silently left two public products with the identical SKU.
$legacySkuCollisionRoot = $tmp . '/upsert-legacy-sku-collision';
mkdir($legacySkuCollisionRoot . '/storage', 0775, true);
$legacySkuCollisionRows = [
    ['id' => '350339', 'sku' => 'ORIGINAL-ERP-SKU', 'precos' => ['preco' => 301.20]],
    ['sku' => 'REUSED-ERP-SKU', 'precos' => ['preco' => 19.90]],
];
file_put_contents(
    $legacySkuCollisionRoot . '/storage/products-cache-ativos.json',
    json_encode($legacySkuCollisionRows)
);
$legacySkuCollisionRejected = false;
try {
    svoei_upsert_catalog_product($legacySkuCollisionRoot, [
        'id' => '350339',
        'sku' => 'REUSED-ERP-SKU',
        'precos' => ['preco' => 301.30],
    ]);
} catch (RuntimeException $e) {
    $legacySkuCollisionRejected = $e->getMessage() === 'catalog_sku_identity_conflict';
}
t_assert($legacySkuCollisionRejected, 'ID-first upsert must reject another legacy row owning the new SKU');
$legacySkuCollisionAfter = t_items($legacySkuCollisionRoot . '/storage/products-cache-ativos.json');
t_assert(count($legacySkuCollisionAfter) === 2, 'rejected collision must preserve catalog row count');
t_assert(($legacySkuCollisionAfter[0]['sku'] ?? '') === 'ORIGINAL-ERP-SKU', 'identified row must remain unchanged');
t_assert(($legacySkuCollisionAfter[1]['sku'] ?? '') === 'REUSED-ERP-SKU', 'legacy row must remain unchanged');


// An ID mismatch with a matching SKU must fail closed, not corrupt the cache.
$unmatchedRoot = $tmp . '/price-id-mismatch';
mkdir($unmatchedRoot . '/storage', 0775, true);
file_put_contents($unmatchedRoot . '/storage/products-cache-ativos.json', json_encode([$collisionRows[0]]));
$idMismatchRejected = false;
try {
    svoei_update_catalog_price($unmatchedRoot, '35039', 450.00, 0.0, '350339', 12345);
} catch (RuntimeException $e) {
    $idMismatchRejected = $e->getMessage() === 'catalog_product_not_found';
}
t_assert($idMismatchRejected, 'known conflicting ERP ID must reject price mutation');
$unmatchedAfter = t_items($unmatchedRoot . '/storage/products-cache-ativos.json');
t_assert((float)($unmatchedAfter[0]['precos']['preco'] ?? 0) === 9.99, 'ID mismatch must not change a different product');

// Compatibility: legacy SKU-only cache rows may be updated if the ID is absent.
$legacyRoot = $tmp . '/price-id-legacy';
mkdir($legacyRoot . '/storage', 0775, true);
file_put_contents($legacyRoot . '/storage/products-cache-ativos.json', json_encode([
    ['sku' => '35039', 'precos' => ['preco' => 8.88]],
]));
svoei_update_catalog_price($legacyRoot, '35039', 199.00, 0.0, '350339', 12345);
$legacyAfter = t_items($legacyRoot . '/storage/products-cache-ativos.json');
t_assert((float)($legacyAfter[0]['precos']['preco'] ?? 0) === 199.00, 'ID-less legacy row should retain SKU fallback');


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



// PDV pricing must be derived from the real price-list identity and percent,
// with explicit individual overrides and fail-closed cases. No network calls.
$pdvList = ['id' => 12345, 'descricao' => 'PDV', 'acrescimoDesconto' => 15.0];
$pdvDetail = ['id' => 12345, 'descricao' => 'PDV', 'acrescimoDesconto' => 15.0, 'excecoes' => null];
$pdvBase = ['id' => 350339, 'precos' => ['preco' => 100, 'precoPromocional' => 80]];
$pdvDefault = svpdv_calculate_product_prices('350339', $pdvBase, $pdvList, $pdvDetail);
t_assert($pdvDefault['preco'] === 115.0, 'PDV percentage must apply to base');
t_assert($pdvDefault['precoPromocional'] === 92.0, 'PDV percentage must apply to promo');
t_assert($pdvDefault['price_source'] === 'olist_pdv', 'PDV provenance missing');
$pdvOverride = $pdvDetail;
$pdvOverride['excecoes'] = ['idProduto' => 350339, 'codigo' => '35039', 'preco' => 105.0, 'precoPromocional' => 90.0];
$pdvFixed = svpdv_calculate_product_prices('350339', $pdvBase, $pdvList, $pdvOverride);
t_assert($pdvFixed['preco'] === 105.0 && $pdvFixed['precoPromocional'] === 90.0, 'PDV fixed exception must override percentage');
$pdvRemovedPromo = $pdvOverride;
$pdvRemovedPromo['excecoes']['precoPromocional'] = null;
$pdvRemoved = svpdv_calculate_product_prices('350339', $pdvBase, $pdvList, $pdvRemovedPromo);
t_assert($pdvRemoved['precoPromocional'] === 0.0, 'PDV promo removal must be respected');
$pdvEqualPromo = $pdvOverride;
$pdvEqualPromo['excecoes']['precoPromocional'] = 105.0;
$pdvNoDiscount = svpdv_calculate_product_prices('350339', $pdvBase, $pdvList, $pdvEqualPromo);
t_assert($pdvNoDiscount['preco'] === 105.0 && $pdvNoDiscount['precoPromocional'] === 0.0, 'Equal PDV prices must not display a fake promotion');
$pdvDiscount = ['id' => 12345, 'descricao' => 'PDV', 'acrescimoDesconto' => -10.0, 'excecoes' => null];
$pdvTen = svpdv_calculate_product_prices('350339', $pdvBase, ['id'=>12345,'descricao'=>'PDV','acrescimoDesconto'=>-10.0], $pdvDiscount);
t_assert($pdvTen['preco'] === 90.0, 'PDV discount percent incorrect');
$pdvFail = static function (array $detail, array $source) use ($pdvBase, $pdvList): bool {
    try { svpdv_calculate_product_prices('350339', $pdvBase, $source, $detail); }
    catch (RuntimeException) { return true; }
    return false;
};
t_assert($pdvFail(['id'=>12345,'descricao'=>'PDV','acrescimoDesconto'=>null,'excecoes'=>null], ['id'=>12345,'descricao'=>'PDV','acrescimoDesconto'=>null]), 'missing percentage must fail closed');
t_assert($pdvFail(['id'=>12345,'descricao'=>'OUTRA','acrescimoDesconto'=>15.0], $pdvList), 'wrong list must fail closed');
t_assert($pdvFail(['id'=>12345,'descricao'=>'PDV','acrescimoDesconto'=>20.0], $pdvList), 'changed percentage must fail closed');
t_assert($pdvFail(['id'=>12345,'descricao'=>'PDV','acrescimoDesconto'=>15.0,'excecoes'=>['idProduto'=>387064728,'preco'=>10]], $pdvList), 'duplicate SKU must not accept other ERP product exception');
$normalizerRejected = false;
try { svoei_normalize_v3_product($detail, ['disponivel'=>7]); }
catch (RuntimeException $e) { $normalizerRejected = $e->getMessage() === 'pdv_prices_required'; }
t_assert($normalizerRejected, 'product import without verified PDV must be refused');

// Quarantine cannot be bypassed by an ID-only event or a new-product fallback.
$quarantineRoot = $tmp . '/quarantine';
mkdir($quarantineRoot . '/storage', 0775, true);
$blockList = [['sku'=>'1C7Q-LKVS-MDOC','olist_product_ids'=>['342902508','387064728'],'reason'=>'duplicate_active_sku']];
$validRow = ['id'=>900,'sku'=>'SAFE-1','precos'=>['preco'=>50.0,'precoPromocional'=>0.0]];
file_put_contents($quarantineRoot . '/storage/products-cache-ativos.json',
    json_encode(['itens'=>[$validRow], 'quarantined_skus'=>$blockList]));
foreach ([
    static fn() => svoei_update_catalog_price($quarantineRoot, '1C7Q-LKVS-MDOC', 52.59, 0.0, '342902508', 982),
    static fn() => svoei_update_catalog_stock($quarantineRoot, '', 10, '387064728'),
    static fn() => svoei_upsert_catalog_product($quarantineRoot, ['id'=>387064728,'sku'=>'1C7Q-LKVS-MDOC']),
] as $attempt) {
    $blocked = false;
    try { $attempt(); } catch (RuntimeException $e) { $blocked = $e->getMessage() === 'catalog_sku_quarantined'; }
    t_assert($blocked, 'quarantined SKU/ID must never re-enter through webhook');
}
svoei_update_catalog_price($quarantineRoot, 'SAFE-1', 65.0, 0.0, '900', 982);
$afterSafeUpdate = json_decode((string)file_get_contents($quarantineRoot . '/storage/products-cache-ativos.json'), true);
t_assert($afterSafeUpdate['quarantined_skus'] === $blockList, 'webhook must preserve collision manifest');
t_assert(count($afterSafeUpdate['itens']) === 1, 'webhook must not publish quarantined ID');
t_assert((float)$afterSafeUpdate['itens'][0]['precos']['preco'] === 65.0, 'unrelated PDV webhook must keep working');

// Even the legacy webhook receiver must never remove the active catalog
// before the authenticated PDV full-sync has completed successfully.
$legacyReceiver = (string)file_get_contents($root . '/api/olist/webhook-receiver.php');
t_assert($legacyReceiver !== '', 'legacy webhook contract must be readable');
t_assert(!str_contains($legacyReceiver, 'rename($cache_file'), 'legacy webhook must not rename active cache pre-sync');
t_assert(!str_contains($legacyReceiver, 'unlink($cache_file'), 'legacy webhook must not delete active cache pre-sync');
t_assert(str_contains($legacyReceiver, 'Cache ativo preservado'), 'legacy webhook must preserve live cache until PDV valid');

$compatPrice = (string)file_get_contents($root . '/api/webhooks/tiny-product-price-sync.php');
t_assert(str_contains($compatPrice, 'svoei_refresh_price_from_v3'), 'legacy price webhook must re-read PDV from authenticated ERP');
t_assert(str_contains($compatPrice, "sv_webhook_secret_valid('tiny-product-price')"), 'legacy price webhook must fail closed on missing secret');
t_assert(!str_contains($compatPrice, 'update_cache_price('), 'legacy price webhook must not apply payload price directly');
$canonicalHandler = (string)file_get_contents($root . '/api/olist/webhook.php');
t_assert(str_contains($canonicalHandler, "sv_webhook_secret_valid('olist')"), 'canonical webhook must fail closed on missing secret');
$unsafeSync = (string)file_get_contents($root . '/api/sync-cache-endpoint.php');
t_assert(str_contains($unsafeSync, 'legacy_cache_sync_disabled'), 'unsafe registration-price sync must stay disabled');
t_assert(!str_contains($unsafeSync, 'file_put_contents('), 'legacy cache sync must never overwrite PDV snapshot');

echo "olist-erp-webhook-contract: ok\n";
