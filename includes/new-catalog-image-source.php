<?php
declare(strict_types=1);

require_once __DIR__ . '/pdo-database.php';
require_once __DIR__ . '/storefront-image-source.php';

/**
 * Supplies feed images exclusively from the reconciled Olist/Tiny catalog.
 * Legacy storefront fields are intentionally cleared instead of used as a
 * fallback: a product is published only when its current catalog has a valid
 * direct Olist image.
 *
 * @param list<array<string,mixed>> $products
 * @return list<array<string,mixed>>
 */
function svncis_enrich_direct_olist_images(array $products, string $root): array
{
    if ($products === []) return $products;

    $skus = [];
    foreach ($products as $product) {
        $sku = trim((string)($product['sku'] ?? ''));
        if ($sku !== '') $skus[$sku] = true;
    }
    if ($skus === []) return $products;

    $pdo = sv_pdo();
    if (!$pdo instanceof PDO) return [];

    $imageBySku = [];
    try {
        foreach (array_chunk(array_keys($skus), 500) as $skuChunk) {
            $placeholders = implode(',', array_fill(0, count($skuChunk), '?'));
            $stmt = $pdo->prepare(
                "SELECT sku, image_url, source
                   FROM olist_product_images
                  WHERE sku IN ({$placeholders})
                    AND image_url IS NOT NULL
                    AND TRIM(image_url) <> ''
                    AND (source = 'erp_olist_export' OR source LIKE 'olist_visual_api:%')
                  ORDER BY sku ASC, id ASC"
            );
            $stmt->execute($skuChunk);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $sku = trim((string)($row['sku'] ?? ''));
                if ($sku === '' || isset($imageBySku[$sku])) continue;

                $image = svsis_resolve_image_url($row, $root);
                if ($image !== '') $imageBySku[$sku] = $image;
            }
        }
    } catch (Throwable $e) {
        error_log('new catalog image source unavailable: ' . $e->getMessage());
        return [];
    }

    $enriched = [];
    foreach ($products as $product) {
        $sku = trim((string)($product['sku'] ?? ''));
        $image = $sku !== '' ? ($imageBySku[$sku] ?? '') : '';
        if ($image === '') continue;

        // Never inherit a legacy cover from the runtime snapshot.
        $product['image_url'] = $image;
        $product['imagem_principal_url'] = $image;
        $product['primary_image_url'] = $image;
        $product['images'] = [$image];
        $product['imagens'] = [$image];
        $product['images_count'] = 1;
        $enriched[] = $product;
    }

    return $enriched;
}
