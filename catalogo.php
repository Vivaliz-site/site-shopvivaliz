<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

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
    $jsonPath = sv_catalog_root() . '/api/catalog/fallback-products.json';
    if (!is_file($jsonPath) || !is_readable($jsonPath)) return $data = [];
    $decoded = json_decode((string)file_get_contents($jsonPath), true);
    return $data = is_array($decoded) ? $decoded : [];
}

function sv_catalog_products(int $limit, string $query, string $category = ''): array
{
    $decoded = sv_catalog_load();
    if ($decoded === []) {
        return [];
    }

    $products = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) continue;
        $sku  = trim((string)($row['sku'] ?? ''));
        $name = trim((string)($row['name'] ?? 'Produto Vivaliz'));
        $cat  = trim((string)($row['category'] ?? ''));
        if ($query !== '' && stripos($sku . ' ' . $name, $query) === false) continue;
        if ($category !== '' && $cat !== $category) continue;
        $products[] = [
            'sku'              => $sku !== '' ? $sku : (string)($row['id'] ?? 'sem-sku'),
            'name'             => $name !== '' ? $name : 'Produto Vivaliz',
            'image_url'        => trim((string)($row['image_url'] ?? '/favicon.ico')) ?: '/favicon.ico',
            'price'            => (float)($row['price'] ?? 0),
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

function sv_catalog_money(float $value): string
{
    return $value > 0 ? 'R$ ' . number_format($value, 2, ',', '.') : 'Preço sob consulta';
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

function sv_catalog_product_href(array $product): string
{
    $slug = trim((string)($product['slug'] ?? ''));
    return $slug !== '' ? '/produto/' . $slug : sv_catalog_product_url($product);
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

$query      = sv_catalog_query();
$category   = trim((string)($_GET['categoria'] ?? ''));
$products   = sv_catalog_products(200, $query, $category);
$categories = sv_catalog_categories();
$totalStr   = count($products) . ' produto' . (count($products) === 1 ? '' : 's');
$statusText = $products
    ? $totalStr . ($category !== '' ? " em \"{$category}\"" : '') . '.'
    : ($query !== '' ? 'Nenhum produto encontrado.' : 'Catálogo não disponível no momento.');
$pageTitle = sv_catalog_page_title($category, $query);
$metaDescription = sv_catalog_meta_description($category, $query, count($products));
$canonicalUrl = sv_catalog_canonical_url($category);
$structuredData = sv_catalog_structured_data($products, $canonicalUrl, $pageTitle, $metaDescription);
$searchNoindex = $query !== '';
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
    <script>
        window.va = window.va || function () { (window.vaq = window.vaq || []).push(arguments); };
    </script>
    <script defer src="/_vercel/insights/script.js"></script>
    <script>
        window.si = window.si || function () { (window.siq = window.siq || []).push(arguments); };
    </script>
    <script defer src="/_vercel/speed-insights/script.js"></script>
</head>
<body>
    <nav class="navbar">
        <div class="container nav-inner">
            <a class="brand-link" href="/">
                <img src="/images/logo-vivaliz.png" alt="Vivaliz" class="brand-logo-img" onerror="this.src='/images/logo.svg'">
            </a>
            <div class="navbar-menu">
                <a href="/">Home</a>
                <a href="/catalogo" aria-current="page">Catálogo</a>
                <a href="/carrinho.php" class="nav-cart">
                    🛒 Carrinho <span class="cart-badge" id="nav-cart-count"></span>
                </a>
            </div>
        </div>
    </nav>

    <main class="catalog-page">
        <section class="catalog-header">
            <div class="container catalog-header-inner">
                <div>
                    <p class="eyebrow"><?= $category !== '' ? sv_catalog_esc($category) : 'Todos os produtos' ?></p>
                    <h1>Catálogo Vivaliz</h1>
                    <p class="muted"><?= $statusText ?></p>
                </div>
                <form class="catalog-search" role="search" method="get" action="/catalogo">
                    <input id="catalog-search" name="q" type="search" placeholder="Buscar por SKU ou produto" autocomplete="off" value="<?= sv_catalog_esc($query) ?>">
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
            <div class="catalog-trust-strip" aria-label="Informações de confiança do catálogo">
                <div class="catalog-trust-item">🔒 Compra 100% segura</div>
                <div class="catalog-trust-item">🚚 Envio para todo Brasil</div>
                <div class="catalog-trust-item">↩️ 30 dias para troca</div>
            </div>
        </section>

        <section class="container product-grid" id="product-grid" aria-live="polite">
            <?php foreach ($products as $product): ?>
                <?php
                $image      = $product['image_url'] !== '' ? $product['image_url'] : '/favicon.ico';
                $productUrl = sv_catalog_product_href($product);
                $payload = rawurlencode(json_encode([
                    'sku'              => $product['sku'],
                    'name'             => $product['name'],
                    'image_url'        => $image,
                    'price'            => $product['price'],
                    'olist_product_id' => $product['olist_product_id'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                ?>
                <article class="product-card">
                    <a class="product-image" href="<?= sv_catalog_esc($productUrl) ?>">
                        <img src="<?= sv_catalog_esc($image) ?>" alt="<?= sv_catalog_esc($product['name']) ?>" loading="lazy" onerror="this.src='/favicon.ico'">
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
                            <button class="buy-button" type="button" data-product="<?= sv_catalog_esc($payload) ?>">Comprar agora</button>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    </main>

    <footer>
        <div class="container">
            <div class="footer-cols">
                <div><strong>Vivaliz</strong><p>Qualidade e entrega rápida para todo o Brasil.</p></div>
                <div><strong>Navegação</strong><a href="/">Home</a><a href="/catalogo">Catálogo</a><a href="/contato">Contato</a></div>
                <div><strong>Atendimento</strong><a href="/faq">Dúvidas frequentes</a><a href="/politica-privacidade">Privacidade</a></div>
            </div>
            <p class="footer-copy">&copy; 2026 Vivaliz. Todos os direitos reservados.</p>
        </div>
    </footer>

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
