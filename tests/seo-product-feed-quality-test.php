<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$errors = [];
$catalogUrl = getenv('SV_SEO_CATALOG_URL') ?: 'https://shopvivaliz.com.br/api/catalog/products.php?limit=200&available=1&no_cache=1';
$apiRaw = @file_get_contents($catalogUrl);
$apiJson = json_decode(is_string($apiRaw) ? $apiRaw : '', true);
$rows = is_array($apiJson['products'] ?? null) ? $apiJson['products'] : [];
if ($rows === []) {
    $cacheCandidates = [
        $root . '/storage/products-cache-ativos.json',
        '/home/ubuntu/shopvivaliz-deploy/current/storage/products-cache-ativos.json',
    ];
    $catalog = null;
    foreach ($cacheCandidates as $candidate) {
        $raw = @file_get_contents($candidate);
        $decoded = json_decode(is_string($raw) ? $raw : '', true);
        if (is_array($decoded)) {
            $catalog = $decoded;
            break;
        }
    }
    $walk = static function ($node) use (&$walk, &$rows): void {
        if (!is_array($node)) return;
        if (array_is_list($node)) {
            foreach ($node as $item) {
                if (is_array($item)) $rows[] = $item;
            }
            return;
        }
        foreach (['items','itens','products','produtos','data'] as $key) {
            if (isset($node[$key]) && is_array($node[$key])) $walk($node[$key]);
        }
    };
    $walk($catalog);
}
$available = 0;
foreach ($rows as $item) {
    $sku = trim((string)($item['sku'] ?? $item['codigo'] ?? ''));
    $active = strtolower((string)($item['status'] ?? $item['situacao'] ?? 'active')) !== 'inactive'
        && strtolower((string)($item['situacao'] ?? 'A')) !== 'i';
    $stock = (int)($item['stock'] ?? $item['estoque_disponivel'] ?? $item['estoque']['quantidade'] ?? 0);
    $price = (float)($item['price'] ?? $item['precos']['precoPromocional'] ?? $item['precos']['preco'] ?? 0);
    $image = trim((string)($item['image_url'] ?? $item['imagem_principal_url'] ?? ''));
    if ($active && $stock > 0) {
        $available++;
        if ($sku === '') $errors[] = 'available_product_without_sku';
        if ($price <= 0) $errors[] = $sku . ':available_product_without_price';
        if ($image === '') $errors[] = $sku . ':available_product_without_image';
        if (!preg_match('#^https://[^\s]+#', $image)) $errors[] = $sku . ':image_not_https:' . $image;
    }
}
if ($available < 1) $errors[] = 'no_available_products_from_erp_cache';
foreach (['produto.php','sitemap.php','robots.txt','.htaccess'] as $required) {
    if (!is_file($root . '/' . $required)) $errors[] = 'missing_' . $required;
}
$htaccess = @file_get_contents($root . '/.htaccess');
if (!is_string($htaccess) || !preg_match('/RewriteRule\s+\^sitemap\\\\\.xml\$\s+sitemap\.php/i', $htaccess)) {
    $errors[] = 'sitemap_xml_route_missing';
}
$sitemapPhp = @file_get_contents($root . '/sitemap.php');
foreach (["/termos/", "/politica-devolucoes/", "/politica-entrega/"] as $canonicalPath) {
    if (!is_string($sitemapPhp) || !str_contains($sitemapPhp, "'loc' => '" . $canonicalPath . "'")) {
        $errors[] = 'sitemap_missing_canonical_' . $canonicalPath;
    }
}
$productPhp = @file_get_contents($root . '/produto.php');
foreach (["'@type'          => 'Product'", "'@type'         => 'Offer'", "'priceCurrency' => 'BRL'", "'availability'", "'sku'"] as $needle) {
    if (!is_string($productPhp) || !str_contains($productPhp, $needle)) $errors[] = 'produto_schema_missing_' . $needle;
}
if ($errors !== []) {
    fwrite(STDERR, "SEO_PRODUCT_FEED_FAILED\n" . implode("\n", array_slice(array_unique($errors), 0, 80)) . "\n");
    exit(1);
}
echo 'SEO_PRODUCT_FEED_OK available=' . $available . PHP_EOL;
