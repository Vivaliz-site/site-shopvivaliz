<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog-runtime.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=120, stale-while-revalidate=300');
header('X-Content-Type-Options: nosniff');

const SV_CATEGORY_IMAGE_LIMIT = 8;
const SV_CATEGORY_IMAGE_FALLBACK = '/images/logo-vivaliz-square-v2.png';

function sv_category_image_is_usable(string $url): bool
{
    $url = trim($url);
    if ($url === '' || (!str_starts_with($url, '/') && !preg_match('#^https?://#i', $url))) {
        return false;
    }

    return !preg_match('/(?:placeholder|default|no[-_ ]?image|sem[-_ ]?imagem|logo-vivaliz-square)/i', $url);
}

function sv_category_product_key(array $row): string
{
    foreach (['sku', 'id', 'olist_product_id', 'slug'] as $field) {
        $value = strtoupper(trim((string)($row[$field] ?? '')));
        if ($value !== '') {
            return $field . ':' . $value;
        }
    }

    $name = trim((string)($row['name'] ?? $row['descricao'] ?? ''));
    $image = trim((string)($row['image_url'] ?? ''));
    return 'fallback:' . sha1($name . '|' . $image);
}

/** @return list<string> */
function sv_category_product_images(array $row): array
{
    $images = function_exists('svcr_collect_image_urls')
        ? svcr_collect_image_urls($row)
        : (is_array($row['images'] ?? null) ? $row['images'] : []);

    $primary = trim((string)($row['image_url'] ?? ''));
    if ($primary === '' && function_exists('svcr_first_image_url')) {
        $primary = trim((string)svcr_first_image_url($row));
    }
    if ($primary !== '') {
        array_unshift($images, $primary);
    }

    $result = [];
    foreach ($images as $image) {
        if (is_array($image)) {
            $image = $image['url'] ?? $image['image_url'] ?? $image['src'] ?? '';
        }
        $image = trim((string)$image);
        if (!sv_category_image_is_usable($image) || in_array($image, $result, true)) {
            continue;
        }
        $result[] = $image;
    }
    return $result;
}

$categoryData = [];
foreach (svcr_products() as $row) {
    if (!is_array($row)) {
        continue;
    }

    $category = trim((string)($row['category'] ?? ''));
    if ($category === '') {
        continue;
    }

    $normalizedCategory = function_exists('mb_strtolower')
        ? mb_strtolower($category, 'UTF-8')
        : strtolower($category);
    $productName = trim((string)($row['name'] ?? $row['descricao'] ?? 'Produto Vivaliz'));
    $normalizedProductName = function_exists('mb_strtolower')
        ? mb_strtolower($productName, 'UTF-8')
        : strtolower($productName);

    // Mantem a protecao historica contra um item de rede classificado como
    // banheiro na origem. O carrossel deve representar a categoria exibida.
    if ($normalizedCategory === 'banheiro'
        && (str_contains($normalizedProductName, 'gancho de rede') || str_contains($normalizedProductName, 'rede'))) {
        continue;
    }

    if (!isset($categoryData[$category])) {
        $categoryData[$category] = [
            'count' => 0,
            'products' => [],
            'items' => [],
            'image_urls' => [],
            'gallery_fallbacks' => [],
        ];
    }

    $productKey = sv_category_product_key($row);
    if (isset($categoryData[$category]['products'][$productKey])) {
        continue;
    }
    $categoryData[$category]['products'][$productKey] = true;
    $categoryData[$category]['count']++;

    $images = sv_category_product_images($row);
    if ($images === []) {
        continue;
    }

    $sku = trim((string)($row['sku'] ?? ''));
    $primary = $images[0];

    // Uma foto principal por produto distinto vem antes de qualquer galeria do
    // mesmo SKU. Assim cada troca tende a apresentar um item diferente.
    if (!isset($categoryData[$category]['image_urls'][$primary])
        && count($categoryData[$category]['items']) < SV_CATEGORY_IMAGE_LIMIT) {
        $categoryData[$category]['image_urls'][$primary] = true;
        $categoryData[$category]['items'][] = [
            'src' => $primary,
            'name' => $productName,
            'sku' => $sku,
        ];
    }

    foreach (array_slice($images, 1) as $additional) {
        if (isset($categoryData[$category]['image_urls'][$additional])) {
            continue;
        }
        $categoryData[$category]['image_urls'][$additional] = true;
        $categoryData[$category]['gallery_fallbacks'][] = [
            'src' => $additional,
            'name' => $productName,
            'sku' => $sku,
        ];
    }
}

uasort($categoryData, static function (array $left, array $right): int {
    return (int)($right['count'] ?? 0) <=> (int)($left['count'] ?? 0);
});

$categories = [];
foreach ($categoryData as $name => $data) {
    $items = array_values($data['items'] ?? []);

    // Categorias com apenas um produto fotografado ainda podem alternar entre
    // as fotos reais daquele item. Nunca entram bancos de imagem genéricos.
    if (count($items) < 2) {
        foreach ($data['gallery_fallbacks'] ?? [] as $fallback) {
            $src = trim((string)($fallback['src'] ?? ''));
            if ($src === '' || in_array($src, array_column($items, 'src'), true)) {
                continue;
            }
            $items[] = $fallback;
            if (count($items) >= SV_CATEGORY_IMAGE_LIMIT) {
                break;
            }
        }
    }

    $items = array_slice($items, 0, SV_CATEGORY_IMAGE_LIMIT);
    $images = array_values(array_filter(array_map(
        static fn(array $item): string => trim((string)($item['src'] ?? '')),
        $items
    )));

    if ($images === []) {
        $images = [SV_CATEGORY_IMAGE_FALLBACK];
        $items = [[
            'src' => SV_CATEGORY_IMAGE_FALLBACK,
            'name' => (string)$name,
            'sku' => '',
        ]];
    }

    $first = $items[0] ?? [];
    $categories[] = [
        // `category`, `image_url`, `sku` e `product_name` preservam o contrato
        // consumido pelas versões anteriores do reparador da home.
        'category' => (string)$name,
        'name' => (string)$name,
        'count' => (int)($data['count'] ?? 0),
        'image_url' => $images[0],
        'sku' => (string)($first['sku'] ?? ''),
        'product_name' => (string)($first['name'] ?? ''),
        'images' => $images,
        'items' => $items,
    ];
}

echo json_encode([
    'ok' => true,
    'version' => 2,
    'rotation_interval_ms' => 3000,
    'generated_at' => gmdate('c'),
    'categories' => $categories,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
