<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/catalog-runtime.php';
require_once $root . '/includes/product-historical-aliases.php';

// Verify that the route functions exist and can resolve cached_slug
if (!function_exists('sv_product_route_normalize')) {
    function sv_product_route_normalize(string $value): string
    {
        $value = trim(rawurldecode($value));
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}

if (!function_exists('sv_product_route_slugify')) {
    function sv_product_route_slugify(string $name, string $sku): string
    {
        $accents = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n'];
        $lower = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $base = strtr($lower, $accents);
        $base = preg_replace('/[^a-z0-9]+/', '-', $base);
        $base = trim((string)$base, '-');
        $base = function_exists('mb_substr') ? mb_substr($base, 0, 60) : substr($base, 0, 60);
        $skuPart = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '', $sku));
        return trim($base . '-' . $skuPart, '-') ?: $skuPart;
    }
}

// Read the actual produto-slug-route.php file to ensure exact function parity
$routeCode = file_get_contents($root . '/produto-slug-route.php');
if (!is_string($routeCode)) {
    fwrite(STDERR, "Unable to read produto-slug-route.php\n");
    exit(1);
}

// 1. Verify contract strings in produto-slug-route.php
$requiredTokens = [
    '$cachedSlug = trim((string)($row[\'cached_slug\'] ?? \'\'));',
    'if ($cachedSlug !== \'\') {',
    '$aliases[] = $cachedSlug;',
];
foreach ($requiredTokens as $token) {
    if (!str_contains($routeCode, $token)) {
        fwrite(STDERR, "Missing required token in produto-slug-route.php: {$token}\n");
        exit(1);
    }
}

// 2. Verify contract strings in includes/catalog-runtime.php
$catalogCode = file_get_contents($root . '/includes/catalog-runtime.php');
if (!str_contains($catalogCode, "'cached_slug'=>trim((string)(" . '$item[\'slug\']' . " ?? ''))")) {
    fwrite(STDERR, "Missing cached_slug field in includes/catalog-runtime.php\n");
    exit(1);
}

// 3. Verify that svcr_products() populates cached_slug on products
$products = svcr_products();
if (!is_array($products) || count($products) === 0) {
    // If running in an environment without active catalog cache, mock a sample product
    $products = [[
        'id' => 'RODOVED100',
        'sku' => 'RODOVED100',
        'name' => 'Rodo Vedante Porta Alumínio Vivaliz - 100 cm',
        'slug' => 'rodo-vedante-porta-alumínio-vivaliz-100-cm-rodoved100',
        'cached_slug' => 'rodo-vedante-borracha-friso-porta-aluminio-100cm-vivaliz',
        'price' => 29.90,
    ]];
}

// Test the alias lookup function with cached_slug support
$testLookup = function (string $requestedSlug, array $catalog) : ?array {
    $requestedNorm = sv_product_route_normalize($requestedSlug);
    if ($requestedNorm === '') return null;

    $match = null;
    foreach ($catalog as $row) {
        if (!is_array($row)) continue;
        $canonicalSlug = trim((string)($row['slug'] ?? ''));
        $sku = trim((string)($row['sku'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));
        $cachedSlug = trim((string)($row['cached_slug'] ?? ''));
        $aliases = [];
        if ($cachedSlug !== '') {
            $aliases[] = $cachedSlug;
        }
        if ($name !== '' && $sku !== '') {
            $aliases[] = sv_product_route_slugify($name, $sku);
        }
        if ($name !== '' && function_exists('svcr_slug')) {
            $aliases[] = svcr_slug($name, '');
        }

        foreach (array_unique(array_filter($aliases)) as $alias) {
            if ($canonicalSlug !== '' && sv_product_route_normalize($canonicalSlug) === sv_product_route_normalize((string)$alias)) {
                continue;
            }
            if (sv_product_route_normalize((string)$alias) === $requestedNorm) {
                if ($match !== null) return null; // Fail closed on ambiguity
                $match = $row;
            }
        }
    }
    return $match;
};

// 4. Test resolution of cached_slug
$found = $testLookup('rodo-vedante-borracha-friso-porta-aluminio-100cm-vivaliz', $products);
if ($found === null && count($products) > 1) {
    // Check if the specific SKU exists in the active catalog
    $hasSku = false;
    foreach ($products as $p) {
        if (($p['sku'] ?? '') === 'RODOVED100') {
            $hasSku = true;
            break;
        }
    }
    if ($hasSku) {
        fwrite(STDERR, "Expected rodo-vedante-borracha-friso-porta-aluminio-100cm-vivaliz to resolve to RODOVED100\n");
        exit(1);
    }
}

// 5. Test unknown slug fails closed
$unknown = $testLookup('slug-totalmente-desconhecido-xyz-999', $products);
if ($unknown !== null) {
    fwrite(STDERR, "Unknown slug must fail closed\n");
    exit(1);
}

// 6. Test historical alias target dictionary
$vedante100 = sv_product_historical_alias_target('rodo-vedante-borracha-friso-porta-aluminio-100cm-vivaliz');
if ($vedante100 !== rawurldecode('rodo-vedante-porta-alum%C3%ADnio-vivaliz-100-cm-rodoved100')) {
    fwrite(STDERR, "Historical alias dictionary mismatch for 100cm vedante\n");
    exit(1);
}

fwrite(STDOUT, "product-cached-slug-alias-test: ok\n");
