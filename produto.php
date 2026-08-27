<?php
$svProductHasSessionCookie = isset($_COOKIE[session_name()]) && $_COOKIE[session_name()] !== '';
if ($svProductHasSessionCookie && session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: text/html; charset=UTF-8');
if ($svProductHasSessionCookie) {
    header('Cache-Control: private, no-cache, must-revalidate');
} else {
    header('Cache-Control: public, max-age=15, s-maxage=30, stale-while-revalidate=60');
    header('Vary: Cookie', false);
}
require_once __DIR__ . '/includes/catalog-runtime.php';
require_once __DIR__ . '/includes/product-seo.php';

/* ── helpers ── */
function sv_product_default_image(): string
{
    return '/images/logo-vivaliz-square-v2.png';
}

function sv_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function sv_official_base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $official = __DIR__ . '/config/official-site.php';
    $fallback = 'https://shopvivaliz.com.br';
    if (!is_file($official)) {
        return $base = $fallback;
    }

    $data = @include $official;
    $value = is_array($data) ? trim((string)($data['base_url'] ?? '')) : '';
    return $base = ($value !== '' ? rtrim($value, '/') : $fallback);
}

function sv_product_env_load(): void
{
    $path = __DIR__ . '/.env';
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}

function sv_product_db(): ?mysqli
{
    // ERP/Tiny API v3 is the only product registration source. The public
    // product page must not enrich name, description, price, stock, image,
    // category, SEO or status from local tables or legacy snapshots.
    return null;
}

function sv_product_db_row(?mysqli $db, string $sku, string $id): array
{
    return [];
}

function sv_product_merge_db(array $product, array $dbRow): array
{
    return $product;
}

function sv_product_enrich(array $product, string $requestedSku = '', string $requestedId = ''): array
{
    return $product;
}

function sv_product_enrich_many(array $products): array
{
    return $products;
}

function sv_product_trim(string $value, int $width, string $suffix = '...'): string
{
    if ($width <= 0) {
        return '';
    }

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($value, 0, $width, $suffix);
    }

    if (strlen($value) <= $width) {
        return $value;
    }

    $cut = max(0, $width - strlen($suffix));
    return rtrim(substr($value, 0, $cut)) . $suffix;
}

function sv_product_catalog(): array
{
    static $data = null;
    if ($data !== null) return $data;
    return $data = svcr_products();
}

function sv_product_related(string $sku, string $category, string $currentName = '', int $limit = 4): array
{
    $all = sv_product_catalog();
    $ranked = [];
    $normalizeTokens = static function (string $value): array {
        $value = sv_lower($value);
        $value = strtr($value, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','õ'=>'o','ô'=>'o','ú'=>'u','ç'=>'c']);
        $parts = preg_split('/[^a-z0-9]+/', $value) ?: [];
        $stop = ['para','com','sem','kit','unidade','produto','vivaliz','de','da','do','em'];
        return array_values(array_unique(array_filter($parts, static fn(string $part): bool => strlen($part) >= 4 && !in_array($part, $stop, true))));
    };
    $currentTokens = $normalizeTokens($currentName);
    foreach ($all as $row) {
        if (!is_array($row)) continue;
        if (trim((string)($row['sku'] ?? '')) === $sku) continue;
        $rowCat = trim((string)($row['category'] ?? ''));
        $entry = [
            'sku'              => trim((string)($row['sku'] ?? '')),
            'name'             => trim((string)($row['name'] ?? 'Produto Vivaliz')),
            'image_url'        => trim((string)($row['image_url'] ?? sv_product_default_image())) ?: sv_product_default_image(),
            'price'            => (float)($row['price'] ?? 0),
            'stock'            => (int)($row['stock'] ?? 0),
            'olist_product_id' => (string)($row['olist_product_id'] ?? ''),
            'slug'             => trim((string)($row['slug'] ?? '')),
            'category'         => $rowCat,
        ];
        $score = ($category !== '' && $rowCat === $category) ? 100 : 0;
        $shared = array_intersect($currentTokens, $normalizeTokens($entry['name']));
        $score += count($shared) * 12;
        if ((int)$entry['stock'] > 0) $score += 4;
        if ($score > 0) $ranked[] = ['score' => $score, 'entry' => $entry];
    }
    usort($ranked, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
    return array_slice(array_column($ranked, 'entry'), 0, $limit);
}

function sv_slugify(string $name, string $sku): string
{
    $accents = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n'];
    $base = strtr(sv_lower($name), $accents);
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim((string)$base, '-');
    $base = function_exists('mb_substr') ? mb_substr($base, 0, 60) : substr($base, 0, 60);

    $skuPart = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '', $sku));

    return trim($base . '-' . $skuPart, '-') ?: $skuPart;
}

function sv_product_canonical_slug_redirect(string $requestedSlug, string $canonicalSlug): ?string
{
    $requested = strtolower(trim(urldecode($requestedSlug)));
    $canonical = strtolower(trim($canonicalSlug));
    if ($requested === '' || $canonical === '' || $requested === $canonical) {
        return null;
    }

    return '/produto/' . rawurlencode($canonicalSlug);
}

function sv_product_find_slug(string $slug): array
{
    $slugNorm = strtolower(trim(urldecode($slug)));
    if ($slugNorm === '') return [];

    foreach (sv_product_catalog() as $row) {
        if (!is_array($row)) continue;
        $pSlug = strtolower(trim((string)($row['slug'] ?? '')));
        $cSlug = strtolower(sv_slugify((string)($row['name'] ?? ''), (string)($row['sku'] ?? '')));
        $legacyNameSlug = strtolower(svcr_slug((string)($row['name'] ?? ''), ''));
        $rSku  = strtolower(trim((string)($row['sku'] ?? '')));

        if ($pSlug === $slugNorm || $cSlug === $slugNorm || $legacyNameSlug === $slugNorm || $rSku === $slugNorm || ($rSku !== '' && str_ends_with($slugNorm, '-' . $rSku))) {
            return $row;
        }
    }
    return [];
}

function sv_product_find(string $sku, string $id): array
{
    $skuNorm = strtolower(trim($sku));
    $idNorm  = trim($id);
    foreach (sv_product_catalog() as $row) {
        if (!is_array($row)) continue;
        $rSku = strtolower(trim((string)($row['sku'] ?? '')));
        $rId  = trim((string)($row['olist_product_id'] ?? $row['id'] ?? ''));
        if (($skuNorm !== '' && $rSku === $skuNorm) || ($idNorm !== '' && $rId === $idNorm)) return $row;
    }
    return [];
}

function sv_product_infer_brand(array $product): string
{
    $explicit = trim((string)($product['brand'] ?? $product['marca'] ?? ''));
    if ($explicit !== '') return $explicit;

    $name = sv_lower(trim((string)($product['name'] ?? '')));
    $tags = array_map(
        static fn ($tag): string => sv_lower(trim((string)$tag)),
        is_array($product['tags'] ?? null) ? $product['tags'] : []
    );

    foreach (['soprano', 'gedore', 'astra', 'fercar', 'papaiz', 'japi', 'aquatools'] as $brand) {
        if (str_contains($name, $brand) || in_array($brand, $tags, true)) {
            return ucfirst($brand);
        }
    }

    return '';
}

function sv_product_gtin(array $product): string
{
    foreach (['gtin', 'ean', 'barcode'] as $field) {
        $value = preg_replace('/\D+/', '', trim((string)($product[$field] ?? '')));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function sv_product_availability(int $stock): string
{
    return $stock > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
}

function sv_qv(string $key, string $fallback = ''): string
{
    $v = $_GET[$key] ?? $fallback;
    return is_scalar($v) ? trim((string)$v) : $fallback;
}

function sv_product_url(array $product): string
{
    $sku = trim((string)($product['sku'] ?? ''));
    $name = trim((string)($product['name'] ?? ''));
    $slug = trim((string)($product['slug'] ?? '')) ?: ($sku !== '' && $name !== '' ? sv_slugify($name, $sku) : '');
    if ($slug !== '') {
        return '/produto/' . $slug;
    }

    return '/produto?' . http_build_query([
        'sku' => trim((string)($product['sku'] ?? '')),
        'name' => trim((string)($product['name'] ?? '')),
        'image' => trim((string)($product['image_url'] ?? '')),
        'price' => (string)((float)($product['price'] ?? 0)),
        'olist_product_id' => trim((string)($product['olist_product_id'] ?? '')),
    ]);
}

function sv_product_contact_url(string $sku, string $name): string
{
    return '/contato?' . http_build_query([
        'sku' => $sku,
        'produto' => $name,
    ]);
}

function sv_money(float $value): string
{
    if ($value <= 0) {
        return 'Consulte o valor';
    }

    return 'R$ ' . number_format($value, 2, ',', '.');
}

function sv_esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* ── resolução do produto ── */
$slug = sv_qv('slug');
$requestedSku = sv_qv('sku');
$requestedId = sv_qv('id', sv_qv('olist_product_id'));
$resolved = $slug !== '' ? sv_product_find_slug($slug) : sv_product_find($requestedSku, $requestedId);
$resolved = sv_product_enrich($resolved, $requestedSku, $requestedId);
$lookupRequested = $slug !== '' || $requestedSku !== '' || $requestedId !== '';
// Rodada 5 (2026-08-19): /produto.php sem nenhum parametro caia no ramo
// "nao foi pedido lookup, entao nao e notFound" e servia um placeholder
// fake (sku "sem-sku", price 0) com HTTP 200, cacheavel e indexavel, com
// canonical apontando pra uma URL que da 404. Sem slug/sku/id nenhum NAO e
// um caso valido de pagina de produto -- deve sempre cair em 404 como os
// outros casos de produto inexistente. Ver R5-13 no relatorio da Rodada 5.
$notFound = !$lookupRequested || $resolved === [];

$sku      = trim((string)($resolved['sku']             ?? '')) ?: sv_qv('sku', 'sem-sku');
$name     = trim((string)($resolved['name']            ?? '')) ?: sv_qv('name', 'Produto Vivaliz');
$image    = trim((string)($resolved['image_url']       ?? '')) ?: sv_qv('image', sv_product_default_image());
$olistId  = trim((string)($resolved['olist_product_id']?? '')) ?: sv_qv('olist_product_id');
$category = trim((string)($resolved['category']        ?? ''));
$tags     = is_array($resolved['tags'] ?? null) ? $resolved['tags'] : [];
$qScore   = (int)($resolved['quality_score'] ?? 0);
$rawSlug  = trim((string)($resolved['slug'] ?? '')) ?: ($sku !== '' && $name !== '' ? sv_slugify($name, $sku) : '');

if (!$notFound && $slug !== '' && $rawSlug !== '') {
    $redirectPath = sv_product_canonical_slug_redirect($slug, $rawSlug);
    if ($redirectPath !== null) {
        header('Location: ' . $redirectPath, true, 301);
        exit;
    }
}

$priceRaw   = (float)($resolved['price'] ?? (float)sv_qv('price', '0'));
$stockRaw   = (int)($resolved['stock'] ?? 0);
$brandName  = sv_product_infer_brand($resolved);
$gtin       = sv_product_gtin($resolved);
$availability = sv_product_availability($stockRaw);
// ShopVivaliz nao opera com pre-venda: preco invalido/zero significa produto
// indisponivel pra compra, nao "a consultar". Ver docs/AGENTS.md.
$priceLabel = $priceRaw > 0 ? 'R$ ' . number_format($priceRaw, 2, ',', '.') : 'Produto indisponível';
$contactUrl = sv_product_contact_url($sku, $name);
$baseUrl = sv_official_base_url();
// Rodada 5 (2026-08-19): rawSlug sem rawurlencode() quebrava canonical/og:url
// (e o JSON-LD que reusa $canonicalUrl) sempre que o slug tinha caractere
// fora de [a-z0-9-]. Ver R5-6 no relatorio da Rodada 5.
$canonicalUrl = $baseUrl . ($rawSlug !== '' ? '/produto/' . rawurlencode($rawSlug) : '/produto?sku=' . rawurlencode($sku));
$seoTitle = $resolved !== [] ? svseo_title($resolved, 70) : $name;
$seoDescription = $resolved !== [] ? svseo_meta_description($resolved) : '';

$galleryImages = [];
foreach (is_array($resolved['images'] ?? null) ? $resolved['images'] : [] as $galleryUrl) {
    $galleryUrl = trim((string)$galleryUrl);
    if ($galleryUrl !== '' && !in_array($galleryUrl, $galleryImages, true)) {
        $galleryImages[] = $galleryUrl;
    }
}
if ($galleryImages === [] && $image !== '') {
    $galleryImages[] = $image;
}
$galleryImages = $image !== '' && !in_array($image, $galleryImages, true)
    ? array_merge([$image], $galleryImages)
    : $galleryImages;
$galleryImages = array_slice($galleryImages, 0, 12);

$related = $notFound ? [] : sv_product_enrich_many(sv_product_related($sku, $category, $name));
$svNavCurrent = 'produto';
$videoUrl = trim((string)($resolved['video_url'] ?? ''));
$videoEmbedUrl = '';
if ($videoUrl !== '') {
    $youtubeId = '';
    if (preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i', $videoUrl, $match)) {
        $youtubeId = $match[1];
    } elseif (preg_match('%youtube\.com/shorts/([^"&?/ ]{11})%i', $videoUrl, $match)) {
        $youtubeId = $match[1];
    }
    if ($youtubeId !== '') {
        $videoEmbedUrl = 'https://www.youtube.com/embed/' . $youtubeId . '?autoplay=1&rel=0';
    }
}

/* ── V15: descrição automática ── */
$description = trim((string)($resolved['description'] ?? ''));
if ($description === '') {
    $catPart  = $category !== '' ? " da categoria {$category}" : '';
    $tagPart  = !empty($tags) ? ' (' . implode(', ', array_slice($tags, 0, 3)) . ')' : '';
    $description = "Confira {$name}{$catPart}{$tagPart}. Consulte as imagens, o SKU, a disponibilidade e o frete pelo seu CEP antes de comprar.";
}
$cleanDescription = preg_replace('/<br\s*\/?>/i', "\n", $description);
$cleanDescription = preg_replace('/<\/(p|div|li|h[1-6])>/i', "\n", (string)$cleanDescription);
$cleanDescription = strip_tags((string)$cleanDescription);
$cleanDescription = html_entity_decode((string)$cleanDescription, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$cleanDescription = preg_replace("/[ \t]+/", " ", (string)$cleanDescription);
$cleanDescription = preg_replace("/\n\s*\n+/", "\n\n", (string)$cleanDescription);
$cleanDescription = trim((string)$cleanDescription);
$seoDescription = $seoDescription !== '' ? $seoDescription : sv_product_trim($cleanDescription, 155, '');

$specifications = [];
foreach ([
    'Marca' => $brandName,
    'SKU' => $sku,
    'GTIN/EAN' => $gtin,
    'Categoria' => $category,
    'Garantia informada pelo fabricante' => trim((string)($resolved['warranty'] ?? '')),
] as $label => $value) {
    if (trim((string)$value) !== '') $specifications[$label] = trim((string)$value);
}
$dimensionsLabel = [];
foreach (['Largura' => 'width', 'Altura' => 'height', 'Comprimento' => 'length'] as $label => $field) {
    $value = (float)($resolved[$field] ?? 0);
    if ($value > 0) $dimensionsLabel[] = $label . ': ' . rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',') . ' cm';
}
if ($dimensionsLabel !== []) $specifications['Dimensões cadastradas'] = implode(' · ', $dimensionsLabel);
$weight = (float)($resolved['weight'] ?? 0);
if ($weight > 0) $specifications['Peso líquido cadastrado'] = rtrim(rtrim(number_format($weight, 3, ',', '.'), '0'), ',') . ' kg';

if ($notFound) {
    http_response_code(404);
    // Rodada 4 (2026-08-19): ate aqui o 404 de produto herdava o mesmo
    // Cache-Control da rota de sucesso (public, max-age=15, s-maxage=30,
    // stale-while-revalidate=60), permitindo que um 404 ficasse cacheado por
    // ate 60s extras via stale-while-revalidate -- se um produto sair e
    // voltar ao catalogo, o 404 persistiria mais tempo do que deveria. Ver
    // R4-8 no relatorio da Rodada 4.
    header('Cache-Control: no-store', true);
    $name = 'Produto não encontrado';
    $description = 'O produto solicitado não foi localizado no catálogo atual da Vivaliz. Explore outras opções ou fale com a equipe comercial.';
    $seoTitle = $name;
    $seoDescription = $description;
    $canonicalUrl = $baseUrl . '/catalogo/';
    $priceRaw = 0.0;
    $priceLabel = 'Produto indisponível';
    $tags = [];
    $qScore = 0;
}

$breadcrumbItems = [
    [
        '@type' => 'ListItem',
        'position' => 1,
        'name' => 'Início',
        'item' => $baseUrl . '/',
    ],
    [
        '@type' => 'ListItem',
        'position' => 2,
        'name' => 'Produtos',
        'item' => $baseUrl . '/catalogo/',
    ],
];

if ($category !== '') {
    $breadcrumbItems[] = [
        '@type' => 'ListItem',
        'position' => count($breadcrumbItems) + 1,
        'name' => $category,
        'item' => $baseUrl . '/catalogo/?categoria=' . rawurlencode($category),
    ];
}

$breadcrumbItems[] = [
    '@type' => 'ListItem',
    'position' => count($breadcrumbItems) + 1,
    'name' => $name,
    'item' => $canonicalUrl,
];

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => $breadcrumbItems,
];

$faqJsonLd = null;

if ($notFound) {
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        'name' => $name,
        'description' => $description,
        'url' => $canonicalUrl,
    ];
} else {
    $jsonLd = [
        '@context'       => 'https://schema.org',
        '@type'          => 'Product',
        'name'           => $name,
        'image'          => [$image],
        'description'    => $description,
        'sku'            => $sku,
        'mpn'            => $sku,
        'category'       => $category,
        'mainEntityOfPage' => $canonicalUrl,
        'offers'         => [
            '@type'         => 'Offer',
            'url'           => $canonicalUrl,
            'priceCurrency' => 'BRL',
            'price'         => $priceRaw > 0 ? number_format($priceRaw, 2, '.', '') : '0',
            'availability'  => $availability,
            'priceValidUntil' => date('Y-12-31'),
            'itemCondition' => 'https://schema.org/NewCondition',
            'seller'        => ['@type' => 'Organization', 'name' => 'Shopvivaliz'],
        ],
    ];

    if ($gtin !== '') {
        $jsonLd['gtin'] = $gtin;
    }
    if ($brandName !== '') {
        $jsonLd['brand'] = ['@type' => 'Brand', 'name' => $brandName];
    }

    $faqJsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => [
            [
                '@type' => 'Question',
                'name' => 'O produto ' . $name . ' está disponível?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $stockRaw > 0
                        ? 'Sim. O produto está disponível no catálogo atual da ShopVivaliz com preço de ' . $priceLabel . '.'
                        : 'No momento, o produto aparece como esgotado. Você pode solicitar aviso de estoque ou falar com a equipe comercial.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'A ShopVivaliz entrega ' . $name . ' para todo o Brasil?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Sim. O frete é calculado por CEP no carrinho antes do pagamento, conforme disponibilidade das transportadoras.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'Como confirmar se ' . $name . ' é compatível com minha necessidade?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Confira título, descrição, imagens, SKU e categoria na página do produto. Em caso de dúvida sobre medida, aplicação ou compatibilidade, fale com a equipe antes de comprar.',
                ],
            ],
        ],
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#173B63">
    <link rel="icon" href="/images/favicon.svg?v=2026-07-27" type="image/svg+xml">
    <link rel="icon" href="/favicon.png?v=2026-07-27" type="image/png">
    <link rel="alternate icon" href="/favicon.ico?v=2" type="image/x-icon">
    <link rel="apple-touch-icon" href="/favicon.png?v=2">
    <meta name="msapplication-TileColor" content="#173B63">
    <meta name="theme-color" content="#173B63">
    <meta name="description" content="<?= sv_esc($seoDescription) ?>">
    <?php if ($notFound): ?>
        <meta name="robots" content="noindex,follow">
    <?php endif; ?>
    <meta property="og:title" content="<?= sv_esc($seoTitle) ?> | Vivaliz">
    <meta property="og:description" content="<?= sv_esc($seoDescription) ?>">
    <meta property="og:image" content="<?= sv_esc(str_starts_with($image, 'http') ? $image : $baseUrl . $image) ?>">
    <meta property="og:type" content="<?= $notFound ? 'website' : 'product' ?>">
    <meta property="og:url" content="<?= sv_esc($canonicalUrl) ?>">
    <meta property="og:site_name" content="Vivaliz">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="canonical" href="<?= sv_esc($canonicalUrl) ?>">
    <?php if (!$notFound && $image !== ''): ?>
        <link rel="preload" as="image" href="<?= sv_esc(str_starts_with($image, 'http') ? $image : $baseUrl . $image) ?>" fetchpriority="high">
    <?php endif; ?>
    <?php if (!$notFound): ?>
        <meta property="product:price:amount" content="<?= sv_esc(number_format($priceRaw, 2, '.', '')) ?>">
        <meta property="product:price:currency" content="BRL">
        <meta property="product:availability" content="<?= $stockRaw > 0 ? 'in stock' : 'out of stock' ?>">
    <?php endif; ?>
    <title><?= sv_esc($seoTitle) ?> | Vivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/premium-theme.css?v=2026-07-11">
    <link rel="stylesheet" href="/css/product-conversion-v5.css?v=2026-07-26-v2">
    <link rel="stylesheet" href="/css/first-purchase-popup-v1.css?v=2026-07-30-1">
    <link rel="stylesheet" href="/css/zoom-responsive.css?v=2026-07-26-1">
    <!-- Polimento de layout: precisa vir por ultimo para vencer na cascata. -->
    <link rel="stylesheet" href="/css/layout-polish-v1.css?v=2026-07-29-1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Rodada 10 (2026-08-19): JSON_HEX_TAG|JSON_HEX_AMP -- ver R10-1 em catalogo.php -->
    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PRETTY_PRINT) ?></script>
    <script type="application/ld+json"><?= json_encode($breadcrumbJsonLd, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PRETTY_PRINT) ?></script>
    <?php if ($faqJsonLd !== null): ?>
    <script type="application/ld+json"><?= json_encode($faqJsonLd, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PRETTY_PRINT) ?></script>
    <?php endif; ?>
    <?php if (!$notFound): ?>
    <script>
      window.ShopVivalizProductContext = <?= json_encode([
          'sku' => $sku,
          'name' => $name,
          'brand' => $brandName,
          'category' => $category,
          'price' => $priceRaw,
          'quantity' => 1,
          'olist_product_id' => $olistId,
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <?php endif; ?>
    <?php require_once __DIR__ . '/includes/load-custom-css.php'; ?>
    <?php require_once __DIR__ . '/includes/head-analytics.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <main class="container produto-layout">
        <nav class="breadcrumb" aria-label="Navegação estrutural">
            <a href="/">Início</a> › <a href="/catalogo/">Produtos</a>
            <?php if ($category !== ''): ?> › <a href="/catalogo/?categoria=<?= rawurlencode($category) ?>"><?= sv_esc($category) ?></a><?php endif; ?>
            › <span><?= sv_esc(sv_product_trim($name, 40, '...')) ?></span>
        </nav>

        <?php if ($notFound): ?>
        <section class="product-empty-state" aria-label="Produto não encontrado">
            <h1>Produto não encontrado</h1>
            <p class="product-description"><?= sv_esc($description) ?></p>
            <div class="produto-actions">
                <a class="btn btn-primary" href="/catalogo/">Explorar catálogo</a>
                <a class="btn btn-secondary" href="/contato">Falar com a equipe</a>
            </div>
        </section>
        <?php else: ?>
        <div class="product-detail" data-sku="<?= sv_esc($sku) ?>" data-product-id="<?= sv_esc($olistId !== '' ? $olistId : $sku) ?>">
            <!-- Coluna da Esquerda: Galeria de Imagens -->
            <div class="product-gallery-column">
                <div class="product-detail-image skeleton hover-zoom-container" id="product-zoom-box" data-sku="<?= sv_esc($sku) ?>" data-product-id="<?= sv_esc($olistId !== '' ? $olistId : $sku) ?>">
                    <img id="main-product-image" src="<?= sv_esc($image) ?>" alt="<?= sv_esc($name) ?>" width="600" height="600" onerror="this.src='<?= sv_product_default_image() ?>'" loading="eager" fetchpriority="high" decoding="async">
                </div>
                <!-- Miniaturas Interativas -->
                <div class="product-gallery-thumbnails">
                    <?php foreach ($galleryImages as $galleryIndex => $galleryUrl): ?>
                    <button type="button" class="thumb-btn<?= $galleryIndex === 0 ? ' active' : '' ?>" data-src="<?= sv_esc($galleryUrl) ?>" aria-label="Ver imagem <?= $galleryIndex + 1 ?>">
                        <img src="<?= sv_esc($galleryUrl) ?>" alt="<?= sv_esc('Imagem adicional de ' . $name) ?>" width="56" height="56" loading="lazy" decoding="async" onerror="this.src='<?= sv_product_default_image() ?>'">
                    </button>
                    <?php endforeach; ?>
                    <?php if ($videoEmbedUrl !== ''): ?>
                    <button type="button" class="thumb-btn thumb-video" data-type="video" data-src="<?= sv_esc($videoEmbedUrl) ?>" aria-label="Ver vídeo do produto">
                        <img src="<?= sv_esc($image) ?>" alt="<?= sv_esc('Vídeo de ' . $name) ?>" width="56" height="56" loading="lazy" decoding="async" onerror="this.src='<?= sv_product_default_image() ?>'">
                        <div class="play-icon-badge">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="#fff"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                        </div>
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Coluna da Direita: Informações, Compra, Frete e Confiança -->
            <div class="product-detail-copy">
                <div class="product-meta-top">
                    <?php if ($category !== ''): ?>
                        <a href="/catalogo?categoria=<?= rawurlencode($category) ?>" class="product-category-pill"><?= sv_esc($category) ?></a>
                    <?php endif; ?>
                    <?php if ($stockRaw > 0): ?>
                        <span class="stock-pill in-stock">✓ Em Estoque</span>
                    <?php else: ?>
                        <span class="stock-pill out-of-stock">Esgotado</span>
                    <?php endif; ?>
                </div>

                <h1 class="product-main-title"><?= sv_esc($name) ?></h1>

                <div class="product-sku-row">
                    <span class="sku-text">SKU: <strong><?= sv_esc($sku) ?></strong></span>
                    <?php if ($brandName !== ''): ?>
                        <span class="brand-text">Marca: <strong><?= sv_esc($brandName) ?></strong></span>
                    <?php endif; ?>
                </div>

                <!-- Bloco Unificado de Preço -->
                <div class="product-price-block">
                    <?php if ($stockRaw > 0 && $stockRaw <= 5): ?>
                        <div class="urgency-tag">
                            <i>🔥</i> Apenas <?= $stockRaw ?> unidades restantes!
                        </div>
                    <?php endif; ?>
                    <div class="product-price-label"><?= sv_esc($priceLabel) ?></div>
                    <?php if ($priceRaw > 0): ?>
                        <div class="price-perks">
                            <div class="perk-pix">
                                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                                <span>PIX com aprovação imediata</span>
                            </div>
                            <div class="perk-card">
                                <span>Parcelamento conforme a opção de pagamento escolhida</span>
                            </div>
                        </div>
                    <?php elseif ($stockRaw <= 0): ?>
                        <span class="out-of-stock-badge">Esgotado</span>
                    <?php else: ?>
                        <span class="price-hint">Fale com a equipe para confirmar valor e disponibilidade</span>
                    <?php endif; ?>
                </div>

                <!-- Ações de Compra: Quantidade + Botão Comprar -->
                <?php if ($priceRaw > 0 && $stockRaw > 0): ?>
                <div class="product-buy-group">
                    <div class="product-qty-wrapper" aria-label="Quantidade">
                        <button type="button" class="qty-btn" id="qty-minus" aria-label="Diminuir quantidade">−</button>
                        <input type="number" id="product-qty-input" min="1" max="99" value="1" aria-label="Quantidade" readonly>
                        <button type="button" class="qty-btn" id="qty-plus" aria-label="Aumentar quantidade">+</button>
                    </div>
                    <button class="btn btn-primary btn-cta btn-buy-main main-buy-button" type="button" id="buy-now" data-sku="<?= sv_esc($sku) ?>" data-product-id="<?= sv_esc($olistId !== '' ? $olistId : $sku) ?>" data-add-to-cart="1">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
                        COMPRAR AGORA
                    </button>
                </div>

                <!-- Calculador Único de Frete -->
                <div class="product-frete-box">
                    <label for="p-frete-cep" class="frete-header">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0b4f88" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
                        <span>Calcular frete e prazo de entrega</span>
                    </label>
                    <div class="frete-input-row">
                        <input type="text" id="p-frete-cep" placeholder="Digite seu CEP (ex: 01310-100)" maxlength="9" inputmode="numeric" aria-label="CEP para entrega">
                        <button type="button" id="p-frete-btn" class="btn btn-frete">Calcular</button>
                    </div>
                    <div id="p-frete-result" class="frete-result-box" aria-live="polite"></div>
                </div>

                <!-- Faixa Consolidada de Confiança / Garantia -->
                <div class="product-trust-strip">
                    <div class="trust-pill">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        <div>
                            <strong>Compra Segura</strong>
                            <small>Ambiente protegido</small>
                        </div>
                    </div>
                    <div class="trust-pill">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        <div>
                            <strong><?= trim((string)($resolved['warranty'] ?? '')) !== '' ? 'Garantia ' . sv_esc((string)$resolved['warranty']) : 'Garantia de Fábrica' ?></strong>
                            <small>Produto original</small>
                        </div>
                    </div>
                    <div class="trust-pill">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"></polyline><path d="M20 20v-7a4 4 0 0 0-4-4H4"></path></svg>
                        <div>
                            <strong>7 Dias para Devolução</strong>
                            <small>Sem burocracia</small>
                        </div>
                    </div>
                </div>

                <?php elseif ($priceRaw > 0 && $stockRaw <= 0): ?>
                <div class="stock-alert-form" id="stock-alert-form">
                    <h4>Produto Esgotado 😢</h4>
                    <p>Insira seu e-mail abaixo e avisaremos assim que chegar nova remessa.</p>
                    <form id="frm-stock-alert">
                        <input type="email" id="alert-email" placeholder="Seu melhor e-mail" required>
                        <button type="submit" class="btn btn-primary">Avise-me!</button>
                    </form>
                    <div id="alert-msg"></div>
                </div>
                <?php else: ?>
                <button class="btn btn-disabled" type="button" disabled style="width: 100%;">Produto indisponível</button>
                <?php endif; ?>

                <!-- Especificações Técnicas -->
                <?php if ($specifications !== []): ?>
                <section class="product-specs-card" aria-labelledby="product-specifications-title">
                    <h2 id="product-specifications-title">Informações do Produto</h2>
                    <dl class="specs-grid">
                        <?php foreach ($specifications as $specLabel => $specValue): ?>
                            <dt><?= sv_esc($specLabel) ?></dt>
                            <dd><?= sv_esc($specValue) ?></dd>
                        <?php endforeach; ?>
                    </dl>
                </section>
                <?php endif; ?>

                <!-- Descrição do Produto -->
                <section class="product-desc-card" aria-labelledby="product-description-title">
                    <h2 id="product-description-title">Descrição</h2>
                    <div class="product-description-text">
                        <?= nl2br(sv_esc($cleanDescription)) ?>
                    </div>
                </section>

                <!-- Suporte Comercial -->
                <div class="product-support-link">
                    <span>Dúvidas sobre aplicação, entrega ou compatibilidade?</span>
                    <a href="/contato">Fale com a equipe da Vivaliz</a>
                </div>
                <div class="status-line" id="product-status"></div>
                <div class="product-sku-line" style="display:none;">SKU: <?= sv_esc($sku) ?></div>
            </div>
        <!-- Avaliacoes reais: sem notas ou depoimentos inventados. -->
        <section class="container sv-reviews-section" style="margin-top: 40px; padding: 24px; background: #fff; border: 1px solid rgba(11,79,136,0.1); border-radius: 20px;">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; margin-bottom: 20px; border-bottom: 1px solid #edf2f7; padding-bottom: 16px;">
                <div>
                    <h3 style="font-size: 20px; font-weight: 800; color: #07345d; margin: 0;">Comprou este produto?</h3>
                    <p style="color:#64748b;margin:6px 0 0;">Envie uma avaliação. O selo de compra verificada só aparece quando pedido e e-mail correspondem a uma compra confirmada.</p>
                </div>
                <a href="/avaliacoes.php?produto=<?= rawurlencode($name) ?>&sku=<?= rawurlencode($sku) ?>" class="btn btn-secondary">Escrever avaliação</a>
            </div>
            <p style="margin:0;color:#475569;">Avaliações publicadas na página inicial passam por moderação. Conteúdo positivo e negativo pode ser publicado quando atende às regras.</p>
        </section>
        <?php endif; ?>
    </main>

    <?php if (!empty($related)): ?>
    <section class="container related-products">
        <h2 class="related-title">Você também pode gostar</h2>
        <div class="product-grid related-grid">
            <?php foreach ($related as $rp):
                // ShopVivaliz nao vende sem preco real: produto sem preco valido
                // nao aparece na vitrine de relacionados (nao ha "consulte o valor").
                if ((float)($rp['price'] ?? 0) <= 0) {
                    continue;
                }
                $rUrl = sv_product_url($rp);
                $rContactUrl = sv_product_contact_url((string)$rp['sku'], (string)$rp['name']);
                $rStock = (int)($rp['stock'] ?? 0);
                $rHasPrice = (float)$rp['price'] > 0 && $rStock > 0;
                $rPayload = rawurlencode(json_encode(['sku' => $rp['sku'], 'name' => $rp['name'], 'image_url' => $rp['image_url'], 'price' => $rp['price'], 'olist_product_id' => $rp['olist_product_id'], 'stock' => $rStock], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            ?>
            <article class="product-card<?= $rStock <= 0 ? ' is-out-of-stock' : '' ?>" data-sku="<?= sv_esc((string)$rp['sku']) ?>" data-product-id="<?= sv_esc((string)($rp['olist_product_id'] ?: $rp['sku'])) ?>">
                <a class="product-image" href="<?= sv_esc($rUrl) ?>" data-sku="<?= sv_esc((string)$rp['sku']) ?>" data-product-id="<?= sv_esc((string)($rp['olist_product_id'] ?: $rp['sku'])) ?>">
                    <img src="<?= sv_esc($rp['image_url']) ?>" alt="<?= sv_esc($rp['name']) ?>" width="400" height="400" loading="lazy" decoding="async" onerror="this.src='<?= sv_product_default_image() ?>'">
                    <?php if ($rStock <= 0): ?><span class="out-of-stock-badge">Esgotado</span><?php endif; ?>
                </a>
                <div class="product-info">
                    <?php if ($rp['category'] !== ''): ?>
                        <div class="product-category"><?= sv_esc($rp['category']) ?></div>
                    <?php endif; ?>
                    <h3><?= sv_esc($rp['name']) ?></h3>
                    <div class="product-price"><?= sv_esc($rp['price'] > 0 ? 'R$ ' . number_format($rp['price'], 2, ',', '.') : 'Consulte o valor') ?></div>
                    <div class="card-actions">
                        <a class="btn btn-secondary card-link" href="<?= sv_esc($rUrl) ?>" data-sku="<?= sv_esc((string)$rp['sku']) ?>" data-product-id="<?= sv_esc((string)($rp['olist_product_id'] ?: $rp['sku'])) ?>">Ver detalhes</a>
                        <?php if ($rHasPrice): ?>
                            <button class="buy-button" type="button" data-product="<?= sv_esc($rPayload) ?>" data-sku="<?= sv_esc((string)$rp['sku']) ?>" data-product-id="<?= sv_esc((string)($rp['olist_product_id'] ?: $rp['sku'])) ?>" data-add-to-cart="1">Comprar</button>
                        <?php elseif ($rStock <= 0): ?>
                            <button class="btn btn-disabled card-link" type="button" disabled>Esgotado</button>
                        <?php else: ?>
                            <a class="btn btn-primary card-link" href="<?= sv_esc($rContactUrl) ?>" data-sku="<?= sv_esc((string)$rp['sku']) ?>" data-product-id="<?= sv_esc((string)($rp['olist_product_id'] ?: $rp['sku'])) ?>">Falar com vendas</a>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <script>
    (function () {
        <?php if ($notFound): ?>
        return;
        <?php endif; ?>
        var product = {
            sku: <?= json_encode($sku, JSON_UNESCAPED_UNICODE) ?>,
            name: <?= json_encode($name, JSON_UNESCAPED_UNICODE) ?>,
            image_url: <?= json_encode($image, JSON_UNESCAPED_UNICODE) ?>,
            price: <?= json_encode($priceRaw) ?>,
            olist_product_id: <?= json_encode($olistId, JSON_UNESCAPED_UNICODE) ?>
        };
        function addToCart(p) {
            var items;
            try { items = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]'); } catch(e) { items = []; }
            var ex = items.find(function(i){ return i.sku === p.sku; });
            if (ex) ex.quantity = (ex.quantity || 1) + 1;
            else items.push(Object.assign({}, p, { quantity: 1 }));
            localStorage.setItem('shopvivaliz_cart', JSON.stringify(items));
            window.dispatchEvent(new CustomEvent('shopvivaliz:add_to_cart', {
                detail: { product_id: String(p.olist_product_id || p.sku || '') }
            }));
            return items;
        }

        var buyNowButton = document.getElementById('buy-now');
        if (buyNowButton) {
            buyNowButton.addEventListener('click', function () {
                addToCart(product);
                window.location.href='/carrinho';
            });
        }

        // Product page freight calculation handler
        var pFreteBtn = document.getElementById('p-frete-btn');
        var pFreteCep = document.getElementById('p-frete-cep');
        var pFreteResult = document.getElementById('p-frete-result');
        if (pFreteBtn && pFreteCep && pFreteResult) {
            function calcProductFrete() {
                var cep = pFreteCep.value.replace(/\D/g, '');
                if (cep.length !== 8) {
                    pFreteResult.innerHTML = '<span style="color:#b91c1c;font-weight:600;">Por favor, digite um CEP válido com 8 dígitos.</span>';
                    return;
                }
                var qtyInput = document.getElementById('product-qty-input');
                var quantity = parseInt(qtyInput ? qtyInput.value : '1', 10) || 1;
                pFreteResult.innerHTML = '<span style="color:#0b4f88;font-weight:600;">Calculando as melhores opções de envio...</span>';
                fetch('/api/melhorenvio/shipping-check-v2.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        cep: cep,
                        items: [{
                            sku: product.sku,
                            quantity: quantity,
                            price: product.price,
                            olist_product_id: product.olist_product_id
                        }]
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var options = Array.isArray(data.shipping_options) ? data.shipping_options : (Array.isArray(data.options) ? data.options : []);
                    if (!data.ok || !options.length) {
                        var msg = data.message || 'Nenhuma opção de frete encontrada para este CEP.';
                        pFreteResult.innerHTML = '<span style="color:#b91c1c;">' + msg + '</span>';
                        return;
                    }
                    var html = '<div class="frete-results-list">';
                    options.slice(0, 4).forEach(function(opt) {
                        var comp = opt.company ? opt.company + ' - ' : '';
                        var name = comp + (opt.name || 'Entrega Padrão');
                        var price = Number(opt.price || 0);
                        var deadline = opt.delivery_time || opt.deadline || '';
                        var daysText = deadline ? ' (até ' + deadline + ' dias úteis)' : '';
                        var formattedPrice = price === 0 ? '<strong style="color:#10b981;">GRÁTIS</strong>' : '<strong>R$ ' + price.toFixed(2).replace('.', ',') + '</strong>';
                        html += '<div class="frete-result-item">' +
                            '<span class="frete-item-name">' + name + daysText + '</span>' +
                            '<span class="frete-item-price">' + formattedPrice + '</span>' +
                        '</div>';
                    });
                    html += '</div>';
                    pFreteResult.innerHTML = html;
                })
                .catch(function() {
                    pFreteResult.innerHTML = '<span style="color:#b91c1c;">Não foi possível cotar o frete no momento. Tente novamente no carrinho.</span>';
                });
            }
            pFreteBtn.addEventListener('click', calcProductFrete);
            pFreteCep.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); calcProductFrete(); } });
            pFreteCep.addEventListener('input', function() {
                var v = pFreteCep.value.replace(/\D/g, '');
                if (v.length > 5) v = v.substring(0, 5) + '-' + v.substring(5, 8);
                pFreteCep.value = v;
                if (v.replace(/\D/g, '').length === 8) calcProductFrete();
            });
        }

        document.querySelectorAll('.buy-button[data-product]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                try {
                    var p = JSON.parse(decodeURIComponent(this.dataset.product));
                    addToCart(p);
                    window.location.href='/carrinho';
                } catch(e) {}
            });
        });

    })();
    </script>
    <script>
    (function(){
        try {
            var c = JSON.parse(localStorage.getItem('shopvivaliz_cart')||'[]');
            var n = c.reduce(function(a,i){ return a+(i.quantity||1); }, 0);
            var b = document.getElementById('nav-cart-count');
            if (b) b.textContent = n > 0 ? n : '';
        } catch(e){}
    })();
    </script>
    <script>
    (function() {
        var frm = document.getElementById('frm-stock-alert');
        if (frm) {
            frm.addEventListener('submit', function(e) {
                e.preventDefault();
                var email = document.getElementById('alert-email').value;
                var msgBox = document.getElementById('alert-msg');
                var btn = frm.querySelector('button');
                
                btn.disabled = true;
                btn.textContent = 'Enviando...';
                msgBox.style.display = 'none';
                
                fetch('/api/catalog/stock-alert.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ sku: product.sku, email: email })
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    msgBox.style.display = 'block';
                    msgBox.style.color = data.ok ? 'green' : 'red';
                    msgBox.textContent = data.message || data.error;
                    if (data.ok) frm.reset();
                })
                .catch(function(err) {
                    msgBox.style.display = 'block';
                    msgBox.style.color = 'red';
                    msgBox.textContent = 'Erro ao enviar. Tente novamente.';
                })
                .finally(function() {
                    btn.disabled = false;
                    btn.textContent = 'Avise-me!';
                });
            });
        }
    })();
    </script>
    <div class="sticky-buy-wrapper">
        <div class="sticky-buy-info">
            <span class="sticky-buy-title"><?= sv_esc($name) ?></span>
            <span class="sticky-buy-price"><?= sv_esc($priceLabel) ?></span>
        </div>
        <button class="btn btn-primary btn-comprar" onclick="document.getElementById('buy-now').click()">Comprar</button>
    </div>

    <script>
    (function() {
        // 1. Gallery Switcher Logic
        const thumbs = document.querySelectorAll('.thumb-btn');
        const mainImg = document.getElementById('main-product-image');
        const container = document.getElementById('product-zoom-box');
        
        thumbs.forEach(function(btn) {
            btn.addEventListener('click', function() {
                thumbs.forEach(function(t) {
                    t.classList.remove('active');
                    t.style.borderColor = '#e2e8f0';
                });
                btn.classList.add('active');
                btn.style.borderColor = '#0b4f88';
                
                const isVideo = btn.getAttribute('data-type') === 'video';
                
                if (isVideo && container) {
                    let iframe = document.getElementById('main-product-video');
                    if (!iframe) {
                        iframe = document.createElement('iframe');
                        iframe.id = 'main-product-video';
                        iframe.style.width = '100%';
                        iframe.style.height = '100%';
                        iframe.style.border = 'none';
                        iframe.style.position = 'absolute';
                        iframe.style.top = '0';
                        iframe.style.left = '0';
                        iframe.style.zIndex = '10';
                        iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
                        iframe.allowFullscreen = true;
                        container.appendChild(iframe);
                    }
                    iframe.src = btn.getAttribute('data-src');
                    iframe.style.display = 'block';
                    if (mainImg) mainImg.style.display = 'none';
                } else {
                    const iframe = document.getElementById('main-product-video');
                    if (iframe) {
                        iframe.style.display = 'none';
                        iframe.src = '';
                    }
                    if (mainImg) {
                        mainImg.style.display = 'block';
                        mainImg.style.transition = 'opacity 0.15s ease';
                        mainImg.style.opacity = '0.3';
                        setTimeout(function() {
                            mainImg.src = btn.getAttribute('data-src');
                            mainImg.style.opacity = '1';
                        }, 150);
                    }
                }
            });
        });

        // Repair 2026-08-21: alterna somente miniaturas de imagem a cada 3s; video permanece manual.
        const imageThumbs = Array.prototype.slice.call(thumbs).filter(function(t){ return t.getAttribute('data-type') !== 'video'; });
        if (imageThumbs.length > 1) {
            let svGalleryIndex = Math.max(0, imageThumbs.findIndex(function(t){ return t.classList.contains('active'); }));
            setInterval(function(){
                if (document.hidden) return;
                const video = document.getElementById('main-product-video');
                if (video && video.style.display !== 'none') return;
                svGalleryIndex = (svGalleryIndex + 1) % imageThumbs.length;
                imageThumbs[svGalleryIndex].click();
            }, 3000);
        }

        // 2. Interactive Zoom Lens Logic
        const img = container ? container.querySelector('img') : null;
        
        if (container && img) {
            container.style.overflow = 'hidden';
            container.style.position = 'relative';
            container.style.cursor = 'zoom-in';
            img.style.transition = 'transform 0.1s ease, transform-origin 0.1s ease';
            
            container.addEventListener('mousemove', function(e) {
                const video = document.getElementById('main-product-video');
                if (video && video.style.display !== 'none') {
                    img.style.transform = 'scale(1)';
                    return;
                }
                const rect = container.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                
                const xPercent = (x / rect.width) * 100;
                const yPercent = (y / rect.height) * 100;
                
                img.style.transformOrigin = xPercent + '% ' + yPercent + '%';
                img.style.transform = 'scale(1.4)';
            });
            
            container.addEventListener('mouseleave', function() {
                img.style.transform = 'scale(1)';
                img.style.transformOrigin = 'center center';
            });
        }
    })();
    </script>

    <script src="/js/product-conversion-v5.js?v=2026-07-26-v3"></script>
    <script src="/js/cro-interactions.js"></script>
    <script src="/js/first-purchase-popup-v1.js?v=2026-07-30-1" defer></script>
    <script src="/js/auto-image-carousel.js?v=20260811-1"></script>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
