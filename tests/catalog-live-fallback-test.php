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
$blockManifest = ['quarantined_skus' => [
    ['sku'=>'1C7Q-LKVS-MDOC','olist_product_ids'=>['342902508','387064728']],
]];
svclf_assert(svcr_is_quarantined_identifier($blockManifest, '1c7q-lkvs-mdoc'), 'duplicate SKU must fail closed in public price endpoint');
svclf_assert(svcr_is_quarantined_identifier($blockManifest, '', '387064728'), 'ID-only access to duplicate SKU must also fail closed');
svclf_assert(!svcr_is_quarantined_identifier($blockManifest, 'SAFE-1'), 'unrelated catalog products must remain accessible');
$products = svcr_products();
svclf_assert(count($products) === 0, 'snapshot antigo sem lista PDV comprovada nao pode expor precos no site');

ob_start();
require $root . '/google-merchant-feed.php';
$feed = (string)ob_get_clean();
$itemCount = substr_count($feed, '<item>');
svclf_assert($itemCount === 0, 'Merchant feed nao pode publicar precos de snapshot sem identidade PDV comprovada');

printf("catalog-live-pdv-fail-closed: ok products=%d merchant_items=%d erp_rows=%d\n", count($products), $itemCount, count($decoded));
