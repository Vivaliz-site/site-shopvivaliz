<?php
declare(strict_types=1);

require_once __DIR__ . '/pdo-database.php';

/**
 * @param list<array<string,mixed>> $products
 * @param array<string,list<string>> $imagesBySku
 * @return list<array<string,mixed>>
 */
function svcie_apply_image_map(array $products, array $imagesBySku): array
{
    foreach ($products as $index => $product) {
        $sku = trim((string)($product['sku'] ?? ''));
        if ($sku === '' || !isset($imagesBySku[$sku])) continue;

        $images = array_values(array_unique(array_filter($imagesBySku[$sku], static fn($url): bool => is_string($url) && preg_match('~^https?://~i', $url) === 1)));
        if ($images === []) continue;

        // Regra de mídia pública: a imagem da vitrine deve vir do produto no ERP.
        // Não usar fallback manual, storage local ou imagem da tabela products.
        $products[$index]['image_url'] = $images[0];
        $products[$index]['images'] = array_slice($images, 0, 10);
        $products[$index]['images_count'] = count($products[$index]['images']);
        $products[$index]['media_source'] = 'erp_product';
    }

    return $products;
}

/**
 * Enriches storefront catalog rows exclusively with images synced from the ERP
 * product media table. This function intentionally refuses non-ERP media sources: price/stock/product data remain from the ERP runtime and product media must also be ERP-originated.
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
    $queries = [
        "SELECT sku, image_url AS url, position AS ord
           FROM olist_product_images
          WHERE sku IN ($placeholders)
            AND image_url IS NOT NULL
            AND TRIM(image_url) <> ''
            AND (source = 'erp_olist_export' OR source LIKE 'olist_visual_api:%' OR source LIKE 'tiny_v3_detail:%' OR source = 'tiny_v3_detail')
          ORDER BY sku, position, id",
        "SELECT sku, primary_image_url AS url, 0 AS ord
           FROM olist_products
          WHERE sku IN ($placeholders)
            AND primary_image_url IS NOT NULL
            AND TRIM(primary_image_url) <> ''"
    ];

    $imagesBySku = [];
    foreach ($queries as $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($skuList);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $sku = trim((string)($row['sku'] ?? ''));
                $url = trim((string)($row['url'] ?? ''));
                if ($sku === '' || $url === '' || preg_match('~^https?://~i', $url) !== 1) continue;
                $imagesBySku[$sku] ??= [];
                if (!in_array($url, $imagesBySku[$sku], true)) $imagesBySku[$sku][] = $url;
            }
        } catch (Throwable $e) {
            error_log('catalog ERP image enrichment failed: ' . $e->getMessage());
        }
    }

    return svcie_apply_image_map($products, $imagesBySku);
}
