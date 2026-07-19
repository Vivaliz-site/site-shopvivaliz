<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/includes/catalog-runtime.php';

/* ── helpers ── */
function sv_product_default_image(): string
{
    return '/images/logo-vivaliz-square.png';
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
    if (!class_exists('mysqli') || !function_exists('mysqli_report')) {
        return null;
    }

    sv_product_env_load();
    $constants = __DIR__ . '/config/constants.php';
    if (is_file($constants)) {
        require_once $constants;
    }

    $host = defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: 'localhost');
    $port = (int)(defined('DB_PORT') ? DB_PORT : (getenv('DB_PORT') ?: 3306));
    $name = defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: '');
    $user = defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: '');
    $pass = defined('DB_PASS') ? DB_PASS : (getenv('DB_PASS') ?: '');
    if ($name === '' || $user === '') {
        return null;
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $db = @new mysqli((string)$host, (string)$user, (string)$pass, (string)$name, $port);
    if ($db->connect_errno) {
        return null;
    }

    $db->set_charset('utf8mb4');
    return $db;
}

function sv_product_db_row(?mysqli $db, string $sku, string $id): array
{
    if (!$db instanceof mysqli || ($sku === '' && $id === '')) {
        return [];
    }

    $sql = "SELECT
                p.id,
                p.sku,
                COALESCE(op.olist_product_id, '') AS olist_product_id,
                COALESCE(op.olist_id, '') AS olist_id,
                COALESCE(NULLIF(p.name, ''), NULLIF(op.name, ''), '') AS name,
                COALESCE(NULLIF(p.description, ''), '') AS description,
                COALESCE(p.price, 0) AS price,
                COALESCE(p.stock, 0) AS stock,
                COALESCE(NULLIF(op.primary_image_url, ''), NULLIF(p.image_url, ''), '') AS image_url
            FROM products p
            LEFT JOIN olist_products op ON op.sku = p.sku
            WHERE (? <> '' AND p.sku = ?)
               OR (? <> '' AND (op.olist_product_id = ? OR op.olist_id = ?))
            ORDER BY p.updated_at DESC, p.id DESC
            LIMIT 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('sssss', $sku, $sku, $id, $id, $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? ($result->fetch_assoc() ?: []) : [];
    $stmt->close();

    return is_array($row) ? $row : [];
}

function sv_product_merge_db(array $product, array $dbRow): array
{
    if ($dbRow === []) {
        return $product;
    }

    foreach (['sku', 'name', 'olist_product_id'] as $field) {
        if (trim((string)($dbRow[$field] ?? '')) !== '') {
            $product[$field] = (string)$dbRow[$field];
        }
    }

    if (trim((string)($dbRow['image_url'] ?? '')) !== '') {
        $product['image_url'] = (string)$dbRow['image_url'];
    }

    // Preco, descricao e estoque nunca vem do banco local: a tabela
    // `products` pode ficar desatualizada em qualquer direcao (preco 100x
    // maior/menor, descricao antiga ou com texto de teste/agente, estoque
    // zerado ou com valor antigo maior que o real). O catalogo sincronizado
    // (svcr_products(), direto da Tiny) e a unica fonte confiavel para esses
    // tres campos -- sobrescrever com o banco ja mostrou preco 100x errado
    // (ex: R$ 7.798,00 em vez de R$ 77,98) e "X unidades restantes" para
    // produto com estoque real 0, deixando o cliente adicionar ao carrinho
    // um item indisponivel que so falha (com mensagem confusa) na
    // validacao do checkout.

    return $product;
}

function sv_product_enrich(array $product, string $requestedSku = '', string $requestedId = ''): array
{
    $sku = trim((string)($product['sku'] ?? '')) ?: trim($requestedSku);
    $id = trim((string)($product['olist_product_id'] ?? $product['id'] ?? '')) ?: trim($requestedId);
    $db = sv_product_db();
    if (!$db instanceof mysqli) {
        return $product;
    }

    $enriched = sv_product_merge_db($product, sv_product_db_row($db, $sku, $id));
    $db->close();

    return $enriched;
}

function sv_product_enrich_many(array $products): array
{
    if ($products === []) {
        return [];
    }

    $db = sv_product_db();
    if (!$db instanceof mysqli) {
        return $products;
    }

    foreach ($products as $index => $product) {
        if (!is_array($product)) {
            continue;
        }

        $sku = trim((string)($product['sku'] ?? ''));
        $id = trim((string)($product['olist_product_id'] ?? $product['id'] ?? ''));
        $products[$index] = sv_product_merge_db($product, sv_product_db_row($db, $sku, $id));
    }

    $db->close();
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

function sv_product_related(string $sku, string $category, int $limit = 4): array
{
    $all = sv_product_catalog();
    $related = [];
    $fallback = [];
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
        if ($category !== '' && $rowCat === $category) {
            $related[] = $entry;
            if (count($related) >= $limit) return $related;
        } elseif (count($fallback) < $limit) {
            $fallback[] = $entry;
        }
    }
    return array_slice(array_merge($related, $fallback), 0, $limit);
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

function sv_product_find_slug(string $slug): array
{
    foreach (sv_product_catalog() as $row) {
        if (!is_array($row)) continue;
        $persistedSlug = trim((string)($row['slug'] ?? ''));
        $computedSlug = sv_slugify((string)($row['name'] ?? ''), (string)($row['sku'] ?? ''));
        if ($persistedSlug === $slug || $computedSlug === $slug) return $row;
    }
    return [];
}

function sv_product_find(string $sku, string $id): array
{
    foreach (sv_product_catalog() as $row) {
        if (!is_array($row)) continue;
        $rSku = trim((string)($row['sku'] ?? ''));
        $rId  = trim((string)($row['olist_product_id'] ?? $row['id'] ?? ''));
        if (($sku && strcasecmp($rSku, $sku) === 0) || ($id && $rId === $id)) return $row;
    }
    return [];
}

function sv_product_infer_brand(array $product): string
{
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

    return 'Vivaliz';
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

function sv_esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* ── resolução do produto ── */
$slug = sv_qv('slug');
$requestedSku = sv_qv('sku');
$requestedId = sv_qv('id', sv_qv('olist_product_id'));
$resolved = $slug !== '' ? sv_product_find_slug($slug) : sv_product_find($requestedSku, $requestedId);
$resolved = sv_product_enrich($resolved, $requestedSku, $requestedId);
$lookupRequested = $slug !== '' || $requestedSku !== '' || $requestedId !== '';
$notFound = $lookupRequested && $resolved === [];

$sku      = trim((string)($resolved['sku']             ?? '')) ?: sv_qv('sku', 'sem-sku');
$name     = trim((string)($resolved['name']            ?? '')) ?: sv_qv('name', 'Produto Vivaliz');
$image    = trim((string)($resolved['image_url']       ?? '')) ?: sv_qv('image', sv_product_default_image());
$olistId  = trim((string)($resolved['olist_product_id']?? '')) ?: sv_qv('olist_product_id');
$category = trim((string)($resolved['category']        ?? ''));
$tags     = is_array($resolved['tags'] ?? null) ? $resolved['tags'] : [];
$qScore   = (int)($resolved['quality_score'] ?? 0);
$rawSlug  = trim((string)($resolved['slug'] ?? '')) ?: ($sku !== '' && $name !== '' ? sv_slugify($name, $sku) : '');

$priceRaw   = (float)($resolved['price'] ?? (float)sv_qv('price', '0'));
$stockRaw   = (int)($resolved['stock'] ?? 0);
$brandName  = sv_product_infer_brand($resolved);
$gtin       = sv_product_gtin($resolved);
$availability = sv_product_availability($stockRaw);
$priceLabel = $priceRaw > 0 ? 'R$ ' . number_format($priceRaw, 2, ',', '.') : 'Consulte o valor';
$contactUrl = sv_product_contact_url($sku, $name);
$baseUrl = sv_official_base_url();
$canonicalUrl = $baseUrl . ($rawSlug !== '' ? '/produto/' . $rawSlug : '/produto?sku=' . rawurlencode($sku));

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

$related = $notFound ? [] : sv_product_enrich_many(sv_product_related($sku, $category));
$svNavCurrent = 'produto';

/* ── V15: descrição automática ── */
$description = trim((string)($resolved['description'] ?? ''));
if ($description === '') {
    $catPart  = $category !== '' ? " da categoria {$category}" : '';
    $tagPart  = !empty($tags) ? ' (' . implode(', ', array_slice($tags, 0, 3)) . ')' : '';
    $description = "Confira {$name}{$catPart}{$tagPart}. Produto de qualidade com entrega para todo o Brasil. Compre na Vivaliz.";
}

if ($notFound) {
    http_response_code(404);
    $name = 'Produto não encontrado';
    $description = 'O produto solicitado não foi localizado no catálogo atual da Vivaliz. Explore outras opções ou fale com a equipe comercial.';
    $canonicalUrl = $baseUrl . '/catalogo';
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
        'item' => $baseUrl . '/catalogo',
    ],
];

if ($category !== '') {
    $breadcrumbItems[] = [
        '@type' => 'ListItem',
        'position' => count($breadcrumbItems) + 1,
        'name' => $category,
        'item' => $baseUrl . '/catalogo?categoria=' . rawurlencode($category),
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
        'brand'          => ['@type' => 'Brand', 'name' => $brandName],
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
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#173B63">
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <meta name="description" content="<?= sv_esc($description) ?>">
    <?php if ($notFound): ?>
        <meta name="robots" content="noindex,follow">
    <?php endif; ?>
    <meta property="og:title" content="<?= sv_esc($name) ?> | Vivaliz">
    <meta property="og:description" content="<?= sv_esc($description) ?>">
    <meta property="og:image" content="<?= sv_esc(str_starts_with($image, 'http') ? $image : $baseUrl . $image) ?>">
    <meta property="og:type" content="<?= $notFound ? 'website' : 'product' ?>">
    <meta property="og:url" content="<?= sv_esc($canonicalUrl) ?>">
    <meta property="og:site_name" content="Vivaliz">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="canonical" href="<?= sv_esc($canonicalUrl) ?>">
    <title><?= sv_esc($name) ?> | Vivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/premium-theme.css?v=2026-07-11">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?></script>
    <script type="application/ld+json"><?= json_encode($breadcrumbJsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?></script>
</head>
<body>
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <main class="container produto-layout">
        <nav class="breadcrumb" aria-label="Navegação estrutural">
            <a href="/">Início</a> › <a href="/catalogo">Produtos</a>
            <?php if ($category !== ''): ?> › <a href="/catalogo?categoria=<?= rawurlencode($category) ?>"><?= sv_esc($category) ?></a><?php endif; ?>
            › <span><?= sv_esc(sv_product_trim($name, 40, '...')) ?></span>
        </nav>

        <?php if ($notFound): ?>
        <section class="product-empty-state" aria-label="Produto não encontrado">
            <h1>Produto não encontrado</h1>
            <p class="product-description"><?= sv_esc($description) ?></p>
            <div class="produto-actions">
                <a class="btn btn-primary" href="/catalogo">Explorar catálogo</a>
                <a class="btn btn-secondary" href="/contato">Falar com a equipe</a>
            </div>
        </section>
        <?php else: ?>
        <div class="product-detail">
            <div style="display:flex; flex-direction:column; gap:12px; max-width: 100%;">
                <div class="product-detail-image skeleton hover-zoom-container" id="product-zoom-box">
                    <img id="main-product-image" src="<?= sv_esc($image) ?>" alt="<?= sv_esc($name) ?>" onerror="this.src='<?= sv_product_default_image() ?>'" loading="eager">
                </div>
                <!-- Interactive Product Gallery Thumbnails -->
                <div class="product-gallery-thumbnails" style="display:flex; gap:10px; justify-content:center; margin-bottom:12px; flex-wrap:wrap;">
                    <?php foreach ($galleryImages as $galleryIndex => $galleryUrl): ?>
                    <button type="button" class="thumb-btn<?= $galleryIndex === 0 ? ' active' : '' ?>" data-src="<?= sv_esc($galleryUrl) ?>" aria-label="Ver imagem <?= $galleryIndex + 1 ?>"
                            style="width:54px; height:54px; border:<?= $galleryIndex === 0 ? '2px solid #0b4f88' : '1px solid #e2e8f0' ?>; border-radius:8px; overflow:hidden; cursor:pointer; padding:0; background:#fff; transition: border-color 0.2s;">
                        <img src="<?= sv_esc($galleryUrl) ?>" style="width:100%; height:100%; object-fit:cover;" onerror="this.src='<?= sv_product_default_image() ?>'">
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="product-detail-copy">
                <?php if ($category !== ''): ?>
                    <div class="product-category"><?= sv_esc($category) ?></div>
                <?php endif; ?>
                <h1><?= sv_esc($name) ?></h1>
                <div style="color: #fbbf24; font-size: 14px; margin-bottom: 10px;">
                    ★★★★★ <span style="color: #6b7280; font-size: 12px; margin-left: 5px;">(4.9/5 - Excelente)</span>
                </div>
                <div class="product-description"><?= $description ?></div>
                <div class="product-price-block">
                    <?php if ($stockRaw > 0 && $stockRaw <= 5): ?>
                        <div class="urgency-tag">
                            <i>🔥</i> Apenas <?= $stockRaw ?> unidades restantes!
                        </div>
                    <?php endif; ?>
                    <span class="product-price-label"><?= sv_esc($priceLabel) ?></span>
                    <?php if ($priceRaw === 0.0): ?>
                        <span class="price-hint">Fale com a equipe para confirmar valor e disponibilidade</span>
                    <?php elseif ($stockRaw <= 0): ?>
                        <span class="out-of-stock-badge">Esgotado</span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($tags)): ?>
                    <div class="product-tags">
                        <?php foreach ($tags as $tag): ?>
                            <span class="tag"><?= sv_esc($tag) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="product-confidence-box" aria-label="Informações de confiança da compra" style="display: flex; flex-direction: column; gap: 10px; margin: 20px 0; padding: 15px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <div class="confidence-item" style="display: flex; align-items: center; gap: 8px; font-size: 0.95rem; color: #334155;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        <span>Compra segura com ambiente protegido</span>
                    </div>
                    <div class="confidence-item" style="display: flex; align-items: center; gap: 8px; font-size: 0.95rem; color: #334155;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
                        <span>Envio para todo o Brasil</span>
                    </div>
                    <div class="confidence-item" style="display: flex; align-items: center; gap: 8px; font-size: 0.95rem; color: #334155;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                        <span>Suporte comercial antes e depois do pedido</span>
                    </div>
                </div>
                <div class="produto-actions">
                    <?php if ($priceRaw > 0 && $stockRaw > 0): ?>
                        <button class="btn btn-primary btn-large btn-cta btn-premium main-buy-button" type="button" id="buy-now" style="width: 100%; font-size: 1.2rem;">
                            🛒 COMPRAR AGORA
                        </button>
                        <div class="trust-badges-container" style="display: flex; justify-content: space-between; margin-top: 15px; gap: 10px; flex-wrap: wrap;">
                            <div class="trust-badge-item" style="display: flex; align-items: center; gap: 6px; font-size: 0.85rem; color: #64748b;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                <span>Pagamento 100% Seguro</span>
                            </div>
                            <div class="trust-badge-item" style="display: flex; align-items: center; gap: 6px; font-size: 0.85rem; color: #64748b;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                                <span>Garantia de Fábrica</span>
                            </div>
                            <div class="trust-badge-item" style="display: flex; align-items: center; gap: 6px; font-size: 0.85rem; color: #64748b;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"></polyline><path d="M20 20v-7a4 4 0 0 0-4-4H4"></path></svg>
                                <span>7 dias para Devolução</span>
                            </div>
                        </div>
                    <?php elseif ($priceRaw > 0 && $stockRaw <= 0): ?>
                        <div class="stock-alert-form" id="stock-alert-form" style="background: #f9f9f9; padding: 15px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #ddd;">
                            <h4 style="margin-top: 0; color: #d9534f;">Produto Esgotado 😢</h4>
                            <p style="font-size: 0.9em; margin-bottom: 10px;">Mas não se preocupe! Insira seu e-mail abaixo e avisaremos assim que chegar.</p>
                            <form id="frm-stock-alert" style="display: flex; gap: 10px;">
                                <input type="email" id="alert-email" placeholder="Seu melhor e-mail" required style="flex: 1; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
                                <button type="submit" class="btn btn-primary" style="padding: 10px 15px; background: #007bff; border: none; color: #fff; border-radius: 4px; cursor: pointer;">Avise-me!</button>
                            </form>
                            <div id="alert-msg" style="margin-top: 10px; font-size: 0.9em; display: none;"></div>
                        </div>
                    <?php else: ?>
                        <a class="btn btn-primary" href="<?= sv_esc($contactUrl) ?>">Falar com vendas</a>
                    <?php endif; ?>
                    <a class="btn btn-secondary" href="/catalogo<?= $category !== '' ? '?categoria=' . rawurlencode($category) : '' ?>">← Voltar ao catálogo</a>
                </div>
                <div class="product-support-link">
                    Dúvidas sobre aplicação, entrega ou compatibilidade?
                    <a href="/contato">Fale com a equipe da Vivaliz antes de concluir.</a>
                </div>
                <div class="status-line" id="product-status"></div>
                <div class="product-sku-line">SKU: <?= sv_esc($sku) ?></div>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <?php if (!empty($related)): ?>
    <section class="container related-products">
        <h2 class="related-title">Você também pode gostar</h2>
        <div class="product-grid related-grid">
            <?php foreach ($related as $rp):
                $rUrl = sv_product_url($rp);
                $rContactUrl = sv_product_contact_url((string)$rp['sku'], (string)$rp['name']);
                $rStock = (int)($rp['stock'] ?? 0);
                $rHasPrice = (float)$rp['price'] > 0 && $rStock > 0;
                $rPayload = rawurlencode(json_encode(['sku' => $rp['sku'], 'name' => $rp['name'], 'image_url' => $rp['image_url'], 'price' => $rp['price'], 'olist_product_id' => $rp['olist_product_id'], 'stock' => $rStock], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            ?>
            <article class="product-card<?= $rStock <= 0 ? ' is-out-of-stock' : '' ?>">
                <a class="product-image" href="<?= sv_esc($rUrl) ?>">
                    <img src="<?= sv_esc($rp['image_url']) ?>" alt="<?= sv_esc($rp['name']) ?>" loading="lazy" onerror="this.src='<?= sv_product_default_image() ?>'">
                    <?php if ($rStock <= 0): ?><span class="out-of-stock-badge">Esgotado</span><?php endif; ?>
                </a>
                <div class="product-info">
                    <?php if ($rp['category'] !== ''): ?>
                        <div class="product-category"><?= sv_esc($rp['category']) ?></div>
                    <?php endif; ?>
                    <h3><?= sv_esc($rp['name']) ?></h3>
                    <div class="product-price"><?= sv_esc($rp['price'] > 0 ? 'R$ ' . number_format($rp['price'], 2, ',', '.') : 'Consulte o valor') ?></div>
                    <div class="card-actions">
                        <a class="btn btn-secondary card-link" href="<?= sv_esc($rUrl) ?>">Ver detalhes</a>
                        <?php if ($rHasPrice): ?>
                            <button class="buy-button" type="button" data-product="<?= sv_esc($rPayload) ?>">Comprar</button>
                        <?php elseif ($rStock <= 0): ?>
                            <button class="btn btn-disabled card-link" type="button" disabled>Esgotado</button>
                        <?php else: ?>
                            <a class="btn btn-primary card-link" href="<?= sv_esc($rContactUrl) ?>">Falar com vendas</a>
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
            return items;
        }

        var buyNowButton = document.getElementById('buy-now');
        if (buyNowButton) {
            buyNowButton.addEventListener('click', function () {
                addToCart(product);
                if(window.openMiniCart){window.openMiniCart();}else{window.location.href='/carrinho';}
            });
        }

        document.querySelectorAll('.buy-button[data-product]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                try {
                    var p = JSON.parse(decodeURIComponent(this.dataset.product));
                    addToCart(p);
                    if(window.openMiniCart){window.openMiniCart();}else{window.location.href='/carrinho';}
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
        
        thumbs.forEach(function(btn) {
            btn.addEventListener('click', function() {
                thumbs.forEach(function(t) {
                    t.classList.remove('active');
                    t.style.borderColor = '#e2e8f0';
                });
                btn.classList.add('active');
                btn.style.borderColor = '#0b4f88';
                
                if (mainImg) {
                    mainImg.style.transition = 'opacity 0.15s ease';
                    mainImg.style.opacity = '0.3';
                    setTimeout(function() {
                        mainImg.src = btn.getAttribute('data-src');
                        mainImg.style.opacity = '1';
                    }, 150);
                }
            });
        });

        // 2. Interactive Zoom Lens Logic
        const container = document.getElementById('product-zoom-box');
        const img = container ? container.querySelector('img') : null;
        
        if (container && img) {
            container.style.overflow = 'hidden';
            container.style.position = 'relative';
            container.style.cursor = 'zoom-in';
            img.style.transition = 'transform 0.1s ease, transform-origin 0.1s ease';
            
            container.addEventListener('mousemove', function(e) {
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

    <script src="/js/cro-interactions.js"></script>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
