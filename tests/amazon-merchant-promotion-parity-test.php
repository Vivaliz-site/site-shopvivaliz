<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/catalog-runtime.php';
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$promotion = [
    'id' => 389400526,
    'sku' => '35039',
    'precos' => ['preco' => 399.0, 'precoPromocional' => 219.0],
    '_price_list_id' => 991,
    '_price_source' => 'olist_tiny_amazon',
];
$assert(svcr_item_price($promotion) === 219.0,
    'effective storefront price must use the Amazon promotional value');
$assert(svcr_item_original_price($promotion) === 399.0,
    'Merchant regular price must use the same Amazon 991 table');
$assert(svcr_item_original_price(['precos' => ['preco' => 38.9, 'precoPromocional' => 0]]) === 0.0,
    'non-promotional products must not show a fabricated discount');
$assert(svcr_item_original_price(['precos' => ['preco' => 38.9, 'precoPromocional' => 40]]) === 0.0,
    'promotion above the regular price must not become a sale');

$root = dirname(__DIR__);
$feed = (string)file_get_contents($root . '/google-merchant-feed.php');
$runtime = (string)file_get_contents($root . '/includes/catalog-runtime.php');
$assert(str_contains($feed, "svfc_start('google-merchant', 60"),
    'feed file cache must refresh rapidly when authoritative price changes');
$assert(str_contains($feed, 'max-age=60, must-revalidate'),
    'external caches cannot serve stale commercial prices for an hour');
$assert(str_contains($feed, '$salePrice = $originalPrice > $price ? $price : 0.0'),
    'Merchant must publish regular and sale price separately');
$assert(str_contains($runtime, "'original_price'=>svcr_item_original_price("),
    'public catalog must export regular price from ERP for Merchant');

if (is_file($root . '/storage/products-cache-ativos.json')) {
    $found = false;
    foreach (svcr_products() as $row) {
        if (($row['sku'] ?? '') !== '35039') continue;
        $found = true;
        $assert((float)$row['price'] === 219.0, 'published product sale price must match Amazon ERP');
        $assert((float)($row['original_price'] ?? 0) === 399.0,
            'published regular price must match Amazon ERP');
    }
    $assert($found, 'ERP snapshot contains expected promotional SKU 35039');
}
echo "amazon_merchant_promotion_parity=GREEN\n";
