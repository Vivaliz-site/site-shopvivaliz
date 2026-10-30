<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fallbackPath = $root . '/api/catalog/fallback-products.json';

function svclf_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

svclf_assert(is_file($fallbackPath) && is_readable($fallbackPath), 'fallback-products.json precisa existir e ser legivel');
$raw = file_get_contents($fallbackPath);
svclf_assert(is_string($raw) && $raw !== '', 'fallback-products.json nao pode estar vazio');
$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
svclf_assert(is_array($decoded) && count($decoded) >= 20, 'snapshot ERP precisa manter um catalogo util');

$tinyV3Rows = array_filter($decoded, static fn($row): bool =>
    is_array($row) && strtolower(trim((string)($row['sync_source'] ?? ''))) === 'tiny_v3'
);
svclf_assert(count($tinyV3Rows) === count($decoded), 'snapshot alternativo so pode ser aceito quando todas as linhas vierem do Tiny V3');

require_once $root . '/includes/catalog-runtime.php';
svclf_assert(svcr_fallback_products($root) === [], 'fallback legado nao pode publicar produtos');
$products = svcr_products();
$primary = $root . '/storage/products-cache-ativos.json';

ob_start();
require $root . '/google-merchant-feed.php';
$feed = (string)ob_get_clean();
$itemCount = substr_count($feed, '<item>');

// A V3-only snapshot does not attest the Amazon (991) price table. Under an
// absent runtime cache we must fail closed, not publish misleading offers.
if (!is_file($primary)) {
    svclf_assert($products === [], 'cache ausente nao pode ressuscitar precos de snapshot sem Amazon 991');
    svclf_assert($itemCount === 0, 'Merchant sem catalogo Amazon confirmado nao pode publicar ofertas legadas');
} else {
    $cache = json_decode((string)file_get_contents($primary), true);
    svclf_assert(is_array($cache) && (int)($cache['price_list_id'] ?? 0) === 991,
        'catalogo runtime deve provar tabela Amazon 991');
    svclf_assert(count($products) > 0, 'cache Amazon 991 presente deve alimentar o catalogo');
    svclf_assert($itemCount > 0, 'Merchant deve anunciar itens somente do cache Amazon 991');
}

printf("catalog-live-amazon-authority: ok products=%d merchant_items=%d legacy_rows=%d\n", count($products), $itemCount, count($decoded));
