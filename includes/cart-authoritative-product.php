<?php
declare(strict_types=1);

/**
 * Resolve a cart product exclusively from the canonical ERP-v3-derived runtime.
 * Never fall back to the historically stale MySQL products table or a base-price
 * cache; a missing/ambiguous SKU must fail closed rather than accept a false price.
 *
 * @param list<array<string,mixed>> $catalog
 * @return array<string,mixed>|null
 */
function svca_find_catalog_product(array $catalog, string $sku): ?array
{
    $needle = strtolower(trim($sku));
    if ($needle === '') return null;

    $found = null;
    foreach ($catalog as $row) {
        if (!is_array($row)) continue;
        $candidate = strtolower(trim((string)($row['sku'] ?? '')));
        if ($candidate !== $needle) continue;
        if ($found !== null) return null; // Ambiguous SKU: never select arbitrarily.
        $found = $row;
    }
    return $found;
}

/**
 * Validate the full session quantity against the authoritative sellable stock.
 *
 * @param array<string,mixed> $product
 * @return array{ok:bool,status:int,error?:string,available?:int,requested?:int,quantity?:int,price?:float,product_id?:string}
 */
function svca_quote_cart_add(array $product, int $newQuantity, int $alreadyInCart): array
{
    if ($newQuantity < 1 || $newQuantity > 99 || $alreadyInCart < 0 || $alreadyInCart > 99) {
        return ['ok' => false, 'status' => 400, 'error' => 'invalid_quantity'];
    }
    $price = (float)($product['price'] ?? 0);
    if (!is_finite($price) || $price <= 0) {
        return ['ok' => false, 'status' => 422, 'error' => 'Product has invalid price'];
    }
    $stock = max(0, (int)($product['stock'] ?? 0));
    $requested = $newQuantity + $alreadyInCart;
    if ($requested > 99 || $requested > $stock) {
        return [
            'ok' => false, 'status' => 409, 'error' => 'Insufficient stock',
            'available' => $stock, 'requested' => $requested,
        ];
    }
    $productId = trim((string)($product['olist_product_id'] ?? $product['id'] ?? ''));
    if ($productId === '' || trim((string)($product['sku'] ?? '')) === '') {
        return ['ok' => false, 'status' => 422, 'error' => 'Invalid ERP product identity'];
    }
    return [
        'ok' => true, 'status' => 200,
        'quantity' => $requested, 'price' => round($price, 2), 'product_id' => $productId,
    ];
}
