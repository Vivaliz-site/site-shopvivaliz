<?php
declare(strict_types=1);

require_once __DIR__ . '/pdo-database.php';

/**
 * Enriches storefront catalog rows with the primary image stored in the local
 * product mirror. Price/stock/description stay authoritative from the ERP
 * runtime; only missing image fields are filled here.
 *
 * @param list<array<string,mixed>> $products
 * @return list<array<string,mixed>>
 */
function svcie_enrich_images(array $products): array
{
    if ($products === []) return $products;

    $skus = [];
    foreach ($products as $product) {
        $sku = trim((string)($product['sku'] ?? ''));
        if ($sku !== '') $skus[$sku] = true;
    }
    if ($skus === []) return $products;

    $pdo = sv_pdo();
    if (!$pdo instanceof PDO) return $products;

    $skuList = array_keys($skus);
    $placeholders = implode(',', array_fill(0, count($skuList), '?'));
    $sql = "SELECT p.sku,
                   COALESCE(NULLIF(op.primary_image_url, ''), NULLIF(p.image_url, ''), '') AS image_url
              FROM products p
         LEFT JOIN olist_products op ON op.sku = p.sku
             WHERE p.sku IN ($placeholders)";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($skuList);
        $imageBySku = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $sku = trim((string)($row['sku'] ?? ''));
            $image = trim((string)($row['image_url'] ?? ''));
            if ($sku !== '' && $image !== '') $imageBySku[$sku] = $image;
        }
    } catch (Throwable $e) {
        error_log('catalog image enrichment failed: ' . $e->getMessage());
        return $products;
    }

    foreach ($products as $index => $product) {
        $sku = trim((string)($product['sku'] ?? ''));
        $current = trim((string)($product['image_url'] ?? ''));
        if ($current !== '' || $sku === '' || !isset($imageBySku[$sku])) continue;

        $image = $imageBySku[$sku];
        $products[$index]['image_url'] = $image;
        $images = is_array($product['images'] ?? null) ? array_values(array_filter($product['images'])) : [];
        if (!in_array($image, $images, true)) array_unshift($images, $image);
        $products[$index]['images'] = array_slice($images, 0, 10);
        $products[$index]['images_count'] = max((int)($product['images_count'] ?? 0), count($products[$index]['images']));
    }

    return $products;
}
