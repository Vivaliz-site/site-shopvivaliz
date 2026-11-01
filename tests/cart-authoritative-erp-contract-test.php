<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/cart-authoritative-product.php';
$assert = static function (bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException($reason);
};

$product = [
    'id' => '393626238', 'olist_product_id' => '393626238',
    'sku' => '1C7Q-LKVS-MDOC', 'name' => 'Rodizio gel 75mm',
    'price' => 38.90, 'stock' => 4,
];
$catalog = [$product];

$found = svca_find_catalog_product($catalog, '1c7q-lkvs-mdoc');
$assert($found !== null && (string)$found['id'] === '393626238', 'must use ERP identity');
$assert(svca_find_catalog_product($catalog, 'unknown') === null, 'missing SKU must fail closed');
$assert(svca_find_catalog_product([$product, $product], '1C7Q-LKVS-MDOC') === null, 'ambiguous SKU must fail closed');
$assert(svca_find_catalog_product([], '1C7Q-LKVS-MDOC') === null, 'missing canonical catalog must not fall back');

$q = svca_quote_cart_add($product, 1, 0);
$assert($q['ok'] && $q['quantity'] === 1 && abs((float)$q['price'] - 38.90) < 0.001
    && $q['product_id'] === '393626238', 'ERP quote should match source');

$q = svca_quote_cart_add($product, 2, 2);
$assert($q['ok'] && $q['quantity'] === 4, 'increment exactly to available stock');
$q = svca_quote_cart_add($product, 1, 4);
$assert(!$q['ok'] && $q['status'] === 409 && $q['requested'] === 5
    && $q['available'] === 4, 'enforce total session quantity');

$unavailable = $product;
$unavailable['stock'] = 0;
$assert(svca_quote_cart_add($unavailable, 1, 0)['status'] === 409, 'zero sellable stock');
$invalidPrice = $product;
$invalidPrice['price'] = 0;
$assert(svca_quote_cart_add($invalidPrice, 1, 0)['status'] === 422, 'invalid ERP price');
$assert(svca_quote_cart_add($product, 0, 0)['status'] === 400, 'zero quantity');
$assert(svca_quote_cart_add($product, 100, 0)['status'] === 400, 'max quantity');
$assert(svca_quote_cart_add($product, 1, -1)['status'] === 400, 'invalid stored session');

$source = file_get_contents(dirname(__DIR__) . '/api/cart/add.php');
$assert(is_string($source), 'cart API readable');
$assert(str_contains($source, "includes/catalog-runtime.php"), 'cart API must import canonical ERP cache');
$assert(str_contains($source, 'svcr_products()'), 'cart API must select canonical catalog');
$assert(str_contains($source, 'svca_quote_cart_add('), 'cart API must use full cart quantity guard');
$assert(!str_contains($source, "FROM products"), 'cart API cannot query stale products table');
$assert(!str_contains($source, "config/database.php"), 'cart API cannot use MySQL source for price or stock');
echo "cart-authoritative-erp-contract: ok\n";
