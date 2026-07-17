<?php
declare(strict_types=1);

// Precisa iniciar a sessao antes de qualquer output: esta pagina tem HTML
// suficiente antes do include do navbar (JSON-LD, meta tags) para estourar o
// buffer de saida do PHP, o que envia os headers cedo e faz o session_start()
// tardio do navbar.php falhar silenciosamente (usuario aparece deslogado).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/includes/product-price-enrich.php';
require_once __DIR__ . '/includes/catalog-runtime.php';

function sv_catalog_root(): string
{
    return __DIR__;
}

function sv_catalog_query(): string
{
    $value = $_GET['q'] ?? $_GET['busca'] ?? '';
    return is_scalar($value) ? trim((string)$value) : '';
}

function sv_catalog_load(): array
{
    static $data = null;
    if ($data !== null) return $data;
    return $data = svcr_products();
}

function sv_catalog_products(int $limit, string $query, string $category = '', int $offset = 0): array
{
    $decoded = sv_catalog_load();
    if ($decoded === []) {
        return [];
    }

    $products = [];
    $skipped = 0;
    foreach ($decoded as $row) {
        if (!is_array($row)) continue;
        $sku  = trim((string)($row['sku'] ?? ''));
        $name = trim((string)($row['name'] ?? 'Produto Vivaliz'));
        $cat  = trim((string)($row['category'] ?? ''));
        if ($query !== '' && stripos($sku . ' ' . $name, $query) === false) continue;
        if ($category !== '' && $cat !== $category) continue;
        if ($skipped < $offset) {
            $skipped++;
            continue;
        }
        $products[] = [
            'sku'              => $sku !== '' ? $sku : (string)($row['id'] ?? 'sem-sku'),
            'name'             => $name !== '' ? $name : 'Produto Vivaliz',
            'image_url'        => trim((string)($row['image_url'] ?? sv_catalog_default_image())) ?: sv_catalog_default_image(),
            'price'            => (float)($row['price'] ?? 0),
            'stock'            => (int)($row['stock'] ?? 0),
            'images_count'     => (int)($row['images_count'] ?? 0),
            'olist_product_id' => (string)($row['olist_product_id'] ?? ''),
            'category'         => $cat,
            'slug'             => trim((string)($row['slug'] ?? '')),
            'tags'             => is_array($row['tags'] ?? null) ? $row['tags'] : [],
        ];
        if (count($products) >= $limit) break;
    }

    return $products;
}

function sv_catalog_count_matching(string $query, string $category = ''): int
{
    $decoded = sv_catalog_load();
    $count = 0;
    foreach ($decoded as $row) {
        if (!is_array($row)) continue;
        $sku  = trim((string)($row['sku'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));
        $cat  = trim((string)($row['category'] ?? ''));
        if ($query !== '' && stripos($sku . ' ' . $name, $query) === false) continue;
        if ($category !== '' && $cat !== $category) continue;
        $count++;
    }
    return $count;
}

function sv_catalog_categories(): array
{
    $decoded = sv_catalog_load();
    if ($decoded === []) return [];
    $cats = [];
    foreach ($decoded as $row) {
        $cat = trim((string)($row['category'] ?? ''));
        if ($cat !== '') $cats[$cat] = ($cats[$cat] ?? 0) + 1;
    }
    arsort($cats);
    return $cats;
}

function sv_catalog_esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function sv_catalog_default_image(): string
{
    return '/images/logo-vivaliz-square.png';
}

function sv_catalog_money(float $value): string
{
    return $value > 0 ? 'R$ ' . number_format($value, 2, ',', '.') : 'Consulte o valor';
}

function sv_catalog_base_url(): string
{
    return 'https://dev.shopvivaliz.com.br';
}

function sv_catalog_product_url(array $product): string
{
    $params = http_build_query([
        'sku' => $product['sku'],
        'name' => $product['name'],
        'image' => $product['image_url'],
        'price' => (string)$product['price'],
        'olist_product_id' => $product['olist_product_id'],
    ]);
    return '/produto?' . $params;
}

function sv_catalog_slugify(string $name, string $sku): string
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

function sv_catalog_product_href(array $product): string
{
    $sku = trim((string)($product['sku'] ?? ''));
    $name = trim((string)($product['name'] ?? ''));
    $slug = trim((string)($product['slug'] ?? '')) ?: ($sku !== '' && $name !== '' ? sv_catalog_slugify($name, $sku) : '');
    return $slug !== '' ? '/produto/' . $slug : sv_catalog_product_url($product);
}

function sv_catalog_contact_url(array $product): string
{
    return '/contato?' . http_build_query([
        'sku' => $product['sku'] ?? '',
        'produto' => $product['name'] ?? '',
    ]);
}

function sv_catalog_canonical_url(string $category): string
{
    $params = [];
    if ($category !== '') {
        $params['categoria'] = $category;
    }

    $query = http_build_query($params);
    return sv_catalog_base_url() . '/catalogo' . ($query !== '' ? '?' . $query : '');
}

function sv_catalog_page_title(string $category, string $query): string
{
    if ($query !== '' && $category !== '') {
        return $query . ' em ' . $category . ' | Catálogo Vivaliz';
    }
    if ($query !== '') {
        return 'Busca por ' . $query . ' | Catálogo Vivaliz';
    }
    if ($category !== '') {
        return $category . ' | Catálogo Vivaliz';
    }
    return 'Catálogo | Vivaliz';
}

function sv_catalog_meta_description(string $category, string $query, int $count): string
{
    $countText = $count . ' produto' . ($count === 1 ? '' : 's');
    if ($query !== '' && $category !== '') {
        return 'Resultados para "' . $query . '" em ' . $category . ' na Vivaliz. ' . $countText . ' com compra segura, suporte comercial e entrega para todo o Brasil.';
    }
    if ($query !== '') {
        return 'Resultados de busca por "' . $query . '" no catálogo Vivaliz. Explore produtos com compra segura, suporte comercial e entrega para todo o Brasil.';
    }
    if ($category !== '') {
        return 'Explore ' . $category . ' na Vivaliz. ' . $countText . ' com compra segura, atendimento comercial e entrega para todo o Brasil.';
    }
    return 'Catálogo de produtos Vivaliz com compra segura, suporte comercial e entrega para todo o Brasil. Explore rodízios, ferragens, utilidades e muito mais.';
}

function sv_catalog_structured_data(array $products, string $canonicalUrl, string $pageTitle, string $metaDescription): array
{
    $items = [];
    foreach (array_slice($products, 0, 12) as $index => $product) {
        $items[] = [
            '@type' => 'ListItem',
            'position' => $index + 1,
            'url' => sv_catalog_base_url() . sv_catalog_product_href($product),
            'name' => $product['name'],
            'image' => $product['image_url'],
        ];
    }

    return [
        '@context' => 'https://schema.org',
        '@type' => 'CollectionPage',
        'name' => $pageTitle,
        'description' => $metaDescription,
        'url' => $canonicalUrl,
        'isPartOf' => [
            '@type' => 'WebSite',
            'name' => 'Vivaliz',
            'url' => sv_catalog_base_url() . '/',
        ],
        'mainEntity' => [
            '@type' => 'ItemList',
            'numberOfItems' => count($products),
            'itemListElement' => $items,
        ],
    ];
}

$query        = sv_catalog_query();
$category     = trim((string)($_GET['categoria'] ?? ''));
$perPage      = 20;
$totalCount   = sv_catalog_count_matching($query, $category);
$totalPages   = max(1, (int)ceil($totalCount / $perPage));
$currentPage  = max(1, min($totalPages, (int)($_GET['pagina'] ?? 1)));
$offset       = ($currentPage - 1) * $perPage;
$products     = svp_enrich_products(sv_catalog_products($perPage, $query, $category, $offset));
$categories   = sv_catalog_categories();
$totalStr     = $totalCount . ' produto' . ($totalCount === 1 ? '' : 's');
$statusText = $products
    ? $totalStr . ($category !== '' ? " em \"{$category}\"" : '') . '.'
    : ($query !== '' ? 'Nenhum produto encontrado para essa busca.' : 'Explore nossas categorias ou fale com a equipe para localizar o item ideal.');

function sv_catalog_page_url(int $page, string $query, string $category): string
{
    $params = [];
    if ($query !== '') $params['q'] = $query;
    if ($category !== '') $params['categoria'] = $category;
    if ($page > 1) $params['pagina'] = $page;
    $qs = http_build_query($params);
    return '/catalogo' . ($qs !== '' ? '?' . $qs : '');
}
$pageTitle = sv_catalog_page_title($category, $query);
$metaDescription = sv_catalog_meta_description($category, $query, count($products));
$canonicalUrl = sv_catalog_canonical_url($category);
$structuredData = sv_catalog_structured_data($products, $canonicalUrl, $pageTitle, $metaDescription);
$searchNoindex = $query !== '';
$svNavCurrent = 'catalogo';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= sv_catalog_esc($metaDescription) ?>">
    <meta name="theme-color" content="#173B63">
    <?php if ($searchNoindex): ?>
        <meta name="robots" content="noindex,follow">
    <?php endif; ?>
    <meta property="og:title" content="<?= sv_catalog_esc($pageTitle) ?>">
    <meta property="og:description" content="<?= sv_catalog_esc($metaDescription) ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= sv_catalog_esc($canonicalUrl) ?>">
    <meta property="og:site_name" content="Vivaliz">
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="canonical" href="<?= sv_catalog_esc($canonicalUrl) ?>">
    <title><?= sv_catalog_esc($pageTitle) ?></title>
    <link rel="stylesheet" href="/css/style.css">
    <script type="application/ld+json"><?= json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?></script>
</head>
<body>
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <main class="catalog-page">
        <section class="catalog-header">
            <div class="container catalog-header-inner">
                <div>
                    <p class="eyebrow"><?= $category !== '' ? sv_catalog_esc($category) : 'Todos os produtos' ?></p>
                    <h1>Catálogo Vivaliz</h1>
                    <p class="muted"><?= $statusText ?></p>
                </div>
                <form class="catalog-search" role="search" method="get" action="/catalogo">
                    <input id="catalog-search" name="q" type="search" aria-label="Buscar no catálogo" autocomplete="off" value="<?= sv_catalog_esc($query) ?>">
                    <button type="submit">Buscar</button>
                </form>
            </div>
        </section>

        <section class="container catalog-tools">
            <div class="category-filters" role="navigation" aria-label="Filtrar por categoria">
                <a class="cat-filter<?= $category === '' ? ' active' : '' ?>" href="/catalogo">Todos</a>
                <?php foreach ($categories as $cat => $count): ?>
                    <a class="cat-filter<?= $category === $cat ? ' active' : '' ?>"
                       href="/catalogo?categoria=<?= rawurlencode($cat) ?><?= $query !== '' ? '&q=' . rawurlencode($query) : '' ?>">
                        <?= sv_catalog_esc($cat) ?> <span class="cat-count"><?= $count ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <div id="catalog-status" class="status-line"><?= sv_catalog_esc($statusText) ?></div>
            <div class="catalog-trust-strip" aria-label="Informações de confiança do catálogo" style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 15px;">
                <div class="catalog-trust-item" style="display: flex; align-items: center; gap: 6px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <span>Compra 100% segura</span>
                </div>
                <div class="catalog-trust-item" style="display: flex; align-items: center; gap: 6px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
                    <span>Envio para todo Brasil</span>
                </div>
                <div class="catalog-trust-item" style="display: flex; align-items: center; gap: 6px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"></polyline><path d="M20 20v-7a4 4 0 0 0-4-4H4"></path></svg>
                    <span>7 dias para troca</span>
                </div>
            </div>
        </section>

        <section class="container product-grid" id="product-grid" aria-live="polite">
            <?php foreach ($products as $product): ?>
                <?php
                $image      = $product['image_url'] !== '' ? $product['image_url'] : sv_catalog_default_image();
                $productUrl = sv_catalog_product_href($product);
                $contactUrl = sv_catalog_contact_url($product);
                $stock      = (int)($product['stock'] ?? 0);
                $hasPrice   = (float)$product['price'] > 0 && $stock > 0;
                $payload = rawurlencode(json_encode([
                    'sku'              => $product['sku'],
                    'name'             => $product['name'],
                    'image_url'        => $image,
                    'price'            => $product['price'],
                    'olist_product_id' => $product['olist_product_id'],
                    'stock'            => $stock,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                ?>
                <article class="product-card<?= $stock <= 0 ? ' is-out-of-stock' : '' ?>">
                    <a class="product-image" href="<?= sv_catalog_esc($productUrl) ?>">
                        <img src="<?= sv_catalog_esc($image) ?>" alt="<?= sv_catalog_esc($product['name']) ?>" loading="lazy" onerror="this.src='<?= sv_catalog_default_image() ?>'">
                        <?php if ($stock <= 0): ?><span class="out-of-stock-badge">Esgotado</span><?php endif; ?>
                    </a>
                    <div class="product-info">
                        <?php if ($product['category'] !== ''): ?>
                            <div class="product-category"><?= sv_catalog_esc($product['category']) ?></div>
                        <?php endif; ?>
                        <h2><?= sv_catalog_esc($product['name']) ?></h2>
                        <div class="product-price"><?= sv_catalog_esc(sv_catalog_money((float)$product['price'])) ?></div>
                        <?php if (!empty($product['tags'])): ?>
                            <div class="product-tags">
                                <?php foreach (array_slice($product['tags'], 0, 3) as $tag): ?>
                                    <span class="tag"><?= sv_catalog_esc($tag) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="card-actions">
                            <a class="btn btn-secondary card-link" href="<?= sv_catalog_esc($productUrl) ?>">Ver detalhes</a>
                            <?php if ($hasPrice): ?>
                                <button class="buy-button" type="button" data-product="<?= sv_catalog_esc($payload) ?>">Comprar agora</button>
                            <?php elseif ($stock <= 0): ?>
                                <button class="btn btn-disabled card-link" type="button" disabled>Esgotado</button>
                            <?php else: ?>
                                <a class="btn btn-primary card-link" href="<?= sv_catalog_esc($contactUrl) ?>">Falar com vendas</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <?php if ($totalPages > 1): ?>
        <nav class="container catalog-pagination" aria-label="Paginação do catálogo" style="display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;margin:30px 0;">
            <?php if ($currentPage > 1): ?>
                <a class="btn btn-secondary" href="<?= sv_catalog_esc(sv_catalog_page_url($currentPage - 1, $query, $category)) ?>">&laquo; Anterior</a>
            <?php endif; ?>
            <span class="muted">Página <?= $currentPage ?> de <?= $totalPages ?></span>
            <?php if ($currentPage < $totalPages): ?>
                <a class="btn btn-secondary" href="<?= sv_catalog_esc(sv_catalog_page_url($currentPage + 1, $query, $category)) ?>">Próxima &raquo;</a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>

    <script src="/autodev/client.js"></script>
    <script src="/js/catalog.js"></script>
    <script>
    (function(){
        try {
            var cart = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]');
            var count = cart.reduce(function(a,i){ return a+(i.quantity||1); }, 0);
            var badge = document.getElementById('nav-cart-count');
            if (badge) badge.textContent = count > 0 ? count : '';
        } catch(e){}
    })();
    </script>
</body>
</html>
