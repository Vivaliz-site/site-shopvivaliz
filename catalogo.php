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

function sv_catalog_products(int $limit, string $query, string $category = ''): array
{
    $jsonPath = sv_catalog_root() . '/api/catalog/fallback-products.json';
    if (!is_file($jsonPath) || !is_readable($jsonPath)) {
        return [];
    }

    $decoded = json_decode((string)file_get_contents($jsonPath), true);
    if (!is_array($decoded)) {
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
    $jsonPath = sv_catalog_root() . '/api/catalog/fallback-products.json';
    if (!is_file($jsonPath)) return [];
    $decoded = json_decode((string)file_get_contents($jsonPath), true);
    if (!is_array($decoded)) return [];
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

$query      = sv_catalog_query();
$category   = trim((string)($_GET['categoria'] ?? ''));
$products   = sv_catalog_products(200, $query, $category);
$categories = sv_catalog_categories();
$totalStr   = count($products) . ' produto' . (count($products) === 1 ? '' : 's');
$statusText = $products
    ? $totalStr . ($category !== '' ? " em \"{$category}\"" : '') . '.'
    : ($query !== '' ? 'Nenhum produto encontrado.' : 'Catálogo não disponível no momento.');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Catálogo de produtos Vivaliz — rodízios, ferragens, utilidades e muito mais. Compre online com entrega rápida.">
    <meta name="theme-color" content="#173B63">
    <meta property="og:title" content="Catálogo | Vivaliz">
    <meta property="og:description" content="Explore nosso catálogo completo de produtos com qualidade e entrega rápida.">
    <meta property="og:type" content="website">
    <title>Catálogo | Vivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="container nav-inner">
            <a class="brand-link" href="/">
                <span class="brand-logo">V</span>Vivaliz
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
        </section>

        <section class="container product-grid" id="product-grid" aria-live="polite">
            <?php foreach ($products as $product): ?>
                <?php
                $image      = $product['image_url'] !== '' ? $product['image_url'] : '/favicon.ico';
                $slug       = $product['slug'] !== '' ? $product['slug'] : '';
                $productUrl = $slug !== ''
                    ? '/produto/' . $slug
                    : sv_catalog_product_url($product);
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
