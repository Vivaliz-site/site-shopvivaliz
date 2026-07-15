<?php
declare(strict_types=1);

require_once __DIR__ . '/product-price-enrich.php';
require_once __DIR__ . '/catalog-runtime.php';

function svoa_catalog_map(): array {
    $products = svcr_products();
    $products = svp_enrich_products($products);
    $map = [];
    foreach ($products as $row) {
        $sku = trim((string)($row['sku'] ?? ''));
        if ($sku !== '') $map[strtolower($sku)] = $row;
    }
    return $map;
}

function svoa_resolve_items(array $items): array {
    $catalog = svoa_catalog_map();
    $quantities = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $sku = trim((string)($item['sku'] ?? ''));
        if ($sku === '') continue;
        $key = strtolower($sku);
        $quantities[$key] = min(99, ($quantities[$key] ?? 0) + max(1, (int)($item['quantity'] ?? 1)));
    }
    $resolved = [];
    $errors = [];
    foreach ($quantities as $key => $quantity) {
        $row = $catalog[$key] ?? null;
        if (!is_array($row)) { $errors[] = ['sku'=>$key,'error'=>'product_not_found']; continue; }
        $price = (float)($row['price'] ?? 0);
        $stock = max(0, (int)($row['stock'] ?? 0));
        if ($price <= 0) { $errors[] = ['sku'=>(string)($row['sku'] ?? $key),'error'=>'invalid_price']; continue; }
        if ($stock < $quantity) { $errors[] = ['sku'=>(string)($row['sku'] ?? $key),'error'=>'insufficient_stock','available'=>$stock,'requested'=>$quantity]; continue; }
        $resolved[] = [
            'sku' => (string)($row['sku'] ?? $key),
            'name' => trim((string)($row['name'] ?? $row['sku'] ?? $key)),
            'quantity' => $quantity,
            'price' => round($price, 2),
            'olist_product_id' => trim((string)($row['olist_product_id'] ?? $row['id'] ?? '')),
            'stock' => $stock,
        ];
    }
    return ['items'=>$resolved,'errors'=>$errors];
}
