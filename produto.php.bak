<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');

/* ── helpers ── */
function sv_product_catalog(): array
{
    $path = __DIR__ . '/api/catalog/fallback-products.json';
    if (!is_file($path)) return [];
    $d = json_decode((string)file_get_contents($path), true);
    return is_array($d) ? $d : [];
}

function sv_product_find_slug(string $slug): array
{
    foreach (sv_product_catalog() as $row) {
        if (is_array($row) && trim((string)($row['slug'] ?? '')) === $slug) return $row;
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

function sv_qv(string $key, string $fallback = ''): string
{
    $v = $_GET[$key] ?? $fallback;
    return is_scalar($v) ? trim((string)$v) : $fallback;
}

function sv_esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* ── resolução do produto ── */
$slug     = sv_qv('slug');
$resolved = $slug !== '' ? sv_product_find_slug($slug) : sv_product_find(sv_qv('sku'), sv_qv('id', sv_qv('olist_product_id')));

$sku      = trim((string)($resolved['sku']             ?? '')) ?: sv_qv('sku', 'sem-sku');
$name     = trim((string)($resolved['name']            ?? '')) ?: sv_qv('name', 'Produto Vivaliz');
$image    = trim((string)($resolved['image_url']       ?? '')) ?: sv_qv('image', '/favicon.ico');
$olistId  = trim((string)($resolved['olist_product_id']?? '')) ?: sv_qv('olist_product_id');
$category = trim((string)($resolved['category']        ?? ''));
$tags     = is_array($resolved['tags'] ?? null) ? $resolved['tags'] : [];
$qScore   = (int)($resolved['quality_score'] ?? 0);
$rawSlug  = trim((string)($resolved['slug'] ?? ''));

$priceRaw   = (float)($resolved['price'] ?? (float)sv_qv('price', '0'));
$priceLabel = $priceRaw > 0 ? 'R$ ' . number_format($priceRaw, 2, ',', '.') : 'Preço sob consulta';
$canonicalUrl = 'https://dev.shopvivaliz.com.br' . ($rawSlug !== '' ? '/produto/' . $rawSlug : '/produto?sku=' . rawurlencode($sku));

/* ── V15: descrição automática ── */
$description = trim((string)($resolved['description'] ?? ''));
if ($description === '') {
    $catPart  = $category !== '' ? " da categoria {$category}" : '';
    $tagPart  = !empty($tags) ? ' (' . implode(', ', array_slice($tags, 0, 3)) . ')' : '';
    $description = "Confira {$name}{$catPart}{$tagPart}. Produto de qualidade com entrega para todo o Brasil. Compre na Vivaliz.";
}

/* ── V15: JSON-LD Product ── */
$jsonLd = [
    '@context'    => 'https://schema.org',
    '@type'       => 'Product',
    'name'        => $name,
    'image'       => $image,
    'description' => $description,
    'sku'         => $sku,
    'brand'       => ['@type' => 'Brand', 'name' => 'Vivaliz'],
    'offers'      => [
        '@type'         => 'Offer',
        'url'           => $canonicalUrl,
        'priceCurrency' => 'BRL',
        'price'         => $priceRaw > 0 ? number_format($priceRaw, 2, '.', '') : '0',
        'availability'  => 'https://schema.org/InStock',
        'seller'        => ['@type' => 'Organization', 'name' => 'Vivaliz'],
    ],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#173B63">
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <meta name="description" content="<?= sv_esc($description) ?>">
    <meta property="og:title" content="<?= sv_esc($name) ?> | Vivaliz">
    <meta property="og:description" content="<?= sv_esc($description) ?>">
    <meta property="og:image" content="<?= sv_esc($image) ?>">
    <meta property="og:type" content="product">
    <meta property="og:url" content="<?= sv_esc($canonicalUrl) ?>">
    <link rel="canonical" href="<?= sv_esc($canonicalUrl) ?>">
    <title><?= sv_esc($name) ?> | Vivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?></script>
</head>
<body>
    <nav class="navbar">
        <div class="container nav-inner">
            <a class="brand-link" href="/">
                <img src="/images/logo-vivaliz.png" alt="Vivaliz" class="brand-logo-img" onerror="this.src='/images/logo.svg'">
            </a>
            <div class="navbar-menu">
                <a href="/catalogo">Catálogo</a>
                <?php if ($category !== ''): ?>
                    <a href="/catalogo?categoria=<?= rawurlencode($category) ?>"><?= sv_esc($category) ?></a>
                <?php endif; ?>
                <a href="/carrinho.php" class="nav-cart">
                    🛒 Carrinho <span class="cart-badge" id="nav-cart-count"></span>
                </a>
            </div>
        </div>
    </nav>

    <main class="container produto-layout">
        <nav class="breadcrumb" aria-label="Navegação estrutural">
            <a href="/">Início</a> › <a href="/catalogo">Catálogo</a>
            <?php if ($category !== ''): ?> › <a href="/catalogo?categoria=<?= rawurlencode($category) ?>"><?= sv_esc($category) ?></a><?php endif; ?>
            › <span><?= sv_esc(mb_strimwidth($name, 0, 40, '…')) ?></span>
        </nav>

        <div class="product-detail">
            <div class="product-detail-image">
                <img src="<?= sv_esc($image) ?>" alt="<?= sv_esc($name) ?>" onerror="this.src='/favicon.ico'" loading="eager">
            </div>
            <div class="product-detail-copy">
                <?php if ($category !== ''): ?>
                    <div class="product-category"><?= sv_esc($category) ?></div>
                <?php endif; ?>
                <h1><?= sv_esc($name) ?></h1>
                <p class="product-description"><?= sv_esc($description) ?></p>
                <div class="product-price-block">
                    <span class="product-price-label"><?= sv_esc($priceLabel) ?></span>
                    <?php if ($priceRaw === 0.0): ?>
                        <span class="price-hint">Entre em contato para obter o preço</span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($tags)): ?>
                    <div class="product-tags">
                        <?php foreach ($tags as $tag): ?>
                            <span class="tag"><?= sv_esc($tag) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="produto-actions">
                    <button class="btn btn-primary" type="button" id="buy-now">🛒 Comprar agora</button>
                    <a class="btn btn-secondary" href="/catalogo<?= $category !== '' ? '?categoria=' . rawurlencode($category) : '' ?>">← Voltar ao catálogo</a>
                </div>
                <div class="status-line" id="product-status"></div>
                <div class="product-sku-line">SKU: <?= sv_esc($sku) ?></div>
            </div>
        </div>
    </main>

    <script>
    (function () {
        var product = {
            sku: <?= json_encode($sku, JSON_UNESCAPED_UNICODE) ?>,
            name: <?= json_encode($name, JSON_UNESCAPED_UNICODE) ?>,
            image_url: <?= json_encode($image, JSON_UNESCAPED_UNICODE) ?>,
            price: <?= json_encode($priceRaw) ?>,
            olist_product_id: <?= json_encode($olistId, JSON_UNESCAPED_UNICODE) ?>
        };
        document.getElementById('buy-now').addEventListener('click', function () {
            var items;
            try { items = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]'); } catch(e) { items = []; }
            var ex = items.find(function(i){ return i.sku === product.sku; });
            if (ex) ex.quantity = (ex.quantity || 1) + 1;
            else items.push(Object.assign({}, product, { quantity: 1 }));
            localStorage.setItem('shopvivaliz_cart', JSON.stringify(items));
            window.location.href = '/carrinho.php';
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
</body>
</html>
