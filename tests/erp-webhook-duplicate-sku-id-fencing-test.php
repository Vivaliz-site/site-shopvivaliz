<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/olist-erp-webhook.php';

function collision_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function collision_item(array $rows, string $id): array {
    foreach ($rows as $row) if ((string)($row['id'] ?? '') === $id) return $row;
    throw new RuntimeException('expected ID missing: ' . $id);
}
function collision_error(callable $attempt, string $expected): void {
    try { $attempt(); }
    catch (RuntimeException $e) {
        collision_assert($e->getMessage() === $expected, 'unexpected failure: ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('unsafe ambiguous write was permitted');
}

$tmp = sys_get_temp_dir() . '/sv-erp-id-sku-' . bin2hex(random_bytes(6));
mkdir($tmp . '/storage/cache/catalog-api', 0775, true);
$cache = $tmp . '/storage/products-cache-ativos.json';
$first = [
    'id' => '342902508',
    'sku' => '1C7Q-LKVS-MDOC',
    'precos' => ['preco' => 38.90, 'precoPromocional' => 0],
    '_price_list_id' => 991,
    '_price_source' => 'olist_tiny_amazon',
    'estoque_disponivel' => 4,
];
$second = [
    'id' => '387064728',
    'sku' => '1C7Q-LKVS-MDOC',
    'precos' => ['preco' => 10.0, 'precoPromocional' => 0],
    '_price_list_id' => 991,
    '_price_source' => 'olist_tiny_amazon',
    'estoque_disponivel' => 4,
];
try {
    file_put_contents($cache, json_encode([
        'price_list_id' => 991, 'price_source' => 'olist_tiny_amazon',
        'itens' => [$first, $second], 'total' => 2,
    ], JSON_THROW_ON_ERROR));

    // A direct, authorized ERP ID must always select only its own entry,
    // even when its SKU happens to match a different entry first.
    svoei_update_catalog_stock($tmp, '1C7Q-LKVS-MDOC', 7, '387064728');
    $rows = svoei_load_active_catalog($tmp);
    collision_assert((int)collision_item($rows, '342902508')['estoque_disponivel'] === 4,
        'stock webhook for second ID must not modify first ID');
    collision_assert((int)collision_item($rows, '387064728')['estoque_disponivel'] === 7,
        'stock webhook for second ID must update second ID');
    svoei_update_catalog_price($tmp, '1C7Q-LKVS-MDOC', 12.34, 0.0, '387064728');
    $rows = svoei_load_active_catalog($tmp);
    collision_assert((float)collision_item($rows, '342902508')['precos']['preco'] === 38.90,
        'price for second ID must not modify first ID');
    collision_assert((float)collision_item($rows, '387064728')['precos']['preco'] === 12.34,
        'price for second ID must update correct row');

    // SKU-only notifications are ambiguous and MUST fail closed.
    $before = hash_file('sha256', $cache);
    collision_error(
        static fn() => svoei_update_catalog_stock($tmp, '1C7Q-LKVS-MDOC', 99),
        'catalog_product_sku_ambiguous_requires_id'
    );
    collision_error(
        static fn() => svoei_update_catalog_price($tmp, '1C7Q-LKVS-MDOC', 1.0),
        'catalog_product_sku_ambiguous_requires_id'
    );
    collision_error(
        static fn() => svoei_upsert_catalog_product($tmp, ['sku' => '1C7Q-LKVS-MDOC', 'name' => 'Untrusted SKU only']),
        'catalog_product_sku_ambiguous_requires_id'
    );
    collision_assert(hash_file('sha256', $cache) === $before,
        'ambiguous SKU-only notification must not write any cache file');

    // An ERP product ID wins even when the incoming SKU is stale or changed.
    svoei_upsert_catalog_product($tmp, [
        'id' => '387064728', 'sku' => 'NEW-ERP-CODE', 'precos' => ['preco' => 12.34, 'precoPromocional' => 0],
    ]);
    $rows = svoei_load_active_catalog($tmp);
    collision_assert(count($rows) === 2, 'upsert with ERP ID must not insert a duplicate');
    collision_assert((string)collision_item($rows, '342902508')['sku'] === '1C7Q-LKVS-MDOC',
        'SKU change for second ID must not overwrite first ID');
    collision_assert((string)collision_item($rows, '387064728')['sku'] === 'NEW-ERP-CODE',
        'SKU change must follow exact ERP ID');
    svoei_update_catalog_stock($tmp, '1C7Q-LKVS-MDOC', 8, '387064728');
    $rows = svoei_load_active_catalog($tmp);
    collision_assert((int)collision_item($rows, '387064728')['estoque_disponivel'] === 8,
        'old SKU with correct ID must still update right product');

    // ID absent in cache cannot silently update an unrelated SKU match.
    collision_error(
        static fn() => svoei_update_catalog_stock($tmp, '1C7Q-LKVS-MDOC', 50, '999999999'),
        'catalog_product_not_found'
    );

    // After one SKU remains, the older SKU-only fallback can safely match one row.
    svoei_update_catalog_stock($tmp, '1C7Q-LKVS-MDOC', 6);
    $rows = svoei_load_active_catalog($tmp);
    collision_assert((int)collision_item($rows, '342902508')['estoque_disponivel'] === 6,
        'unambiguous SKU-only fallback must still work');

    $canonical = (string)file_get_contents(dirname(__DIR__) . '/api/olist/webhook.php');
    $legacy = (string)file_get_contents(dirname(__DIR__) . '/api/olist/webhook-receiver.php');
    $assertedHelper = (string)file_get_contents(dirname(__DIR__) . '/includes/olist-erp-webhook.php');
    collision_assert(str_contains($canonical, 'svoei_refresh_product_from_v3($root, $productId, $erpSku)'),
        'canonical price webhook must prefer ERP product ID');
    collision_assert(str_contains($legacy, 'svoei_refresh_product_from_v3($root, $productId, $sku)'),
        'legacy webhook must prefer ERP product ID');
    collision_assert(str_contains($assertedHelper, 'catalog_product_sku_ambiguous_requires_id'),
        'SKU collision must be an explicit fail-closed condition');
    collision_assert(!str_contains($assertedHelper, "return (\$sku !== '' && strcasecmp"),
        'old ID-or-SKU matching must be retired');
    echo "erp_webhook_duplicate_sku_id_fencing=GREEN\n";
} finally {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    @rmdir($tmp);
}
