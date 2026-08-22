<?php
declare(strict_types=1);

require_once __DIR__ . '/pdo-database.php';

/**
 * @param list<array<string,mixed>> $products
 * @param array<string,string> $imageBySku
 * @return list<array<string,mixed>>
 */
function svcie_sku_key(string $sku): string
{
    $value = trim($sku);
    if ($value === '') return '';
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($converted) && $converted !== '') $value = $converted;
    return strtoupper((string)preg_replace('/[^A-Z0-9]+/i', '', $value));
}

/** @return array<string,list<string>> */
function svcie_manual_image_map(): array
{
    return [
        // URLs/fotos locais ja existentes no projeto ou em snapshots Olist/Tiny.
        // Somente preenchem imagem ausente; nao alteram preco, estoque ou nome.
        'kit-vedant-90' => ['/uploads/catalog-fixed/kit-vedant-90/1.jpg'],
        'KIT4R-SOPRANO' => [
            '/uploads/catalog-fixed/KIT4R-SOPRANO/1.jpg',
            '/uploads/catalog-fixed/KIT4R-SOPRANO/2.jpg',
            '/uploads/catalog-fixed/KIT4R-SOPRANO/3.jpg',
            '/uploads/catalog-fixed/KIT4R-SOPRANO/4.jpg',
        ],
        'kit4rod35freio' => [
            'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/0420bfd6eb9c1867d1bf59b62b95a6e6.jpg',
            'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/42d034f9ed70129c457c162092c279a1.jpg',
            'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/ba036cc120b110fbbc3d7245fe53d112.jpg',
            'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/f393a68fb7e84c2ea16d1f7e9fb235f9.jpg',
        ],
    ];
}

/** @param array<string,string|list<string>> $imageBySku */
function svcie_apply_image_map(array $products, array $imageBySku): array
{
    $normalizedMap = [];
    foreach ($imageBySku as $sku => $image) {
        $key = svcie_sku_key((string)$sku);
        if ($key !== '') $normalizedMap[$key] = $image;
    }
    foreach (svcie_manual_image_map() as $sku => $images) {
        $key = svcie_sku_key((string)$sku);
        if ($key !== '') $normalizedMap[$key] = $images;
    }

    foreach ($products as $index => $product) {
        $sku = trim((string)($product['sku'] ?? ''));
        $current = trim((string)($product['image_url'] ?? ''));
        $key = svcie_sku_key($sku);
        if ($current !== '' || $sku === '' || $key === '' || !isset($normalizedMap[$key])) continue;

        $mapped = $normalizedMap[$key];
        $mappedImages = is_array($mapped) ? array_values(array_filter(array_map('strval', $mapped))) : [trim((string)$mapped)];
        $mappedImages = array_values(array_filter($mappedImages, static fn(string $image): bool => trim($image) !== ''));
        if ($mappedImages === []) continue;

        $image = trim($mappedImages[0]);
        $products[$index]['image_url'] = $image;
        $images = is_array($product['images'] ?? null) ? array_values(array_filter($product['images'])) : [];
        foreach (array_reverse($mappedImages) as $mappedImage) {
            if (!in_array($mappedImage, $images, true)) array_unshift($images, $mappedImage);
        }
        $products[$index]['images'] = array_slice($images, 0, 10);
        $products[$index]['images_count'] = max((int)($product['images_count'] ?? 0), count($products[$index]['images']));
    }

    return $products;
}

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

    return svcie_apply_image_map($products, $imageBySku);
}
