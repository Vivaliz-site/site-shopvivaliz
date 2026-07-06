<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');

/* ── helpers ── */
function sv_product_catalog(): array
{
    static $data = null;
    if ($data !== null) return $data;
    $path = __DIR__ . '/api/catalog/fallback-products.json';
    if (!is_file($path)) return $data = [];
    $d = json_decode((string)file_get_contents($path), true);
    return $data = is_array($d) ? $d : [];
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
            'image_url'        => trim((string)($row['image_url'] ?? '/favicon.ico')) ?: '/favicon.ico',
            'price'            => (float)($row['price'] ?? 0),
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

function sv_product_url(array $product): string
{
    $slug = trim((string)($product['slug'] ?? ''));
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

function sv_esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* ── resolução do produto ── */
$slug = sv_qv('slug');
$requestedSku = sv_qv('sku');
$requestedId = sv_qv('id', sv_qv('olist_product_id'));
$resolved = $slug !== '' ? sv_product_find_slug($slug) : sv_product_find($requestedSku, $requestedId);
$lookupRequested = $slug !== '' || $requestedSku !== '' || $requestedId !== '';
$notFound = $lookupRequested && $resolved === [];

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

$related = $notFound ? [] : sv_product_related($sku, $category);

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
    $canonicalUrl = 'https://dev.shopvivaliz.com.br/catalogo';
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
        'item' => 'https://dev.shopvivaliz.com.br/',
    ],
    [
        '@type' => 'ListItem',
        'position' => 2,
        'name' => 'Catálogo',
        'item' => 'https://dev.shopvivaliz.com.br/catalogo',
    ],
];

if ($category !== '') {
    $breadcrumbItems[] = [
        '@type' => 'ListItem',
        'position' => count($breadcrumbItems) + 1,
        'name' => $category,
        'item' => 'https://dev.shopvivaliz.com.br/catalogo?categoria=' . rawurlencode($category),
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
        'image'          => $image,
        'description'    => $description,
        'sku'            => $sku,
        'category'       => $category,
        'mainEntityOfPage' => $canonicalUrl,
        'brand'          => ['@type' => 'Brand', 'name' => 'Vivaliz'],
        'offers'         => [
            '@type'         => 'Offer',
            'url'           => $canonicalUrl,
            'priceCurrency' => 'BRL',
            'price'         => $priceRaw > 0 ? number_format($priceRaw, 2, '.', '') : '0',
            'availability'  => 'https://schema.org/InStock',
            'seller'        => ['@type' => 'Organization', 'name' => 'Vivaliz'],
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
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <meta name="description" content="<?= sv_esc($description) ?>">
    <?php if ($notFound): ?>
        <meta name="robots" content="noindex,follow">
    <?php endif; ?>
    <meta property="og:title" content="<?= sv_esc($name) ?> | Vivaliz">
    <meta property="og:description" content="<?= sv_esc($description) ?>">
    <meta property="og:image" content="<?= sv_esc($image) ?>">
    <meta property="og:type" content="<?= $notFound ? 'website' : 'product' ?>">
    <meta property="og:url" content="<?= sv_esc($canonicalUrl) ?>">
    <meta property="og:site_name" content="Vivaliz">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="canonical" href="<?= sv_esc($canonicalUrl) ?>">
    <title><?= sv_esc($name) ?> | Vivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?></script>
    <script type="application/ld+json"><?= json_encode($breadcrumbJsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?></script>
    <script>
        window.va = window.va || function () { (window.vaq = window.vaq || []).push(arguments); };
    </script>
    <script defer src="/_vercel/insights/script.js"></script>
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
                <div class="product-confidence-box" aria-label="Informações de confiança da compra">
                    <div class="confidence-item">🔒 Compra segura com ambiente protegido</div>
                    <div class="confidence-item">🚚 Envio para todo o Brasil</div>
                    <div class="confidence-item">💬 Suporte comercial antes e depois do pedido</div>
                </div>
                <div class="produto-actions">
                    <button class="btn btn-primary" type="button" id="buy-now">🛒 Comprar agora</button>
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
                $rPayload = rawurlencode(json_encode(['sku' => $rp['sku'], 'name' => $rp['name'], 'image_url' => $rp['image_url'], 'price' => $rp['price'], 'olist_product_id' => $rp['olist_product_id']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            ?>
            <article class="product-card">
                <a class="product-image" href="<?= sv_esc($rUrl) ?>">
                    <img src="<?= sv_esc($rp['image_url']) ?>" alt="<?= sv_esc($rp['name']) ?>" loading="lazy" onerror="this.src='/favicon.ico'">
                </a>
                <div class="product-info">
                    <?php if ($rp['category'] !== ''): ?>
                        <div class="product-category"><?= sv_esc($rp['category']) ?></div>
                    <?php endif; ?>
                    <h3><?= sv_esc($rp['name']) ?></h3>
                    <div class="product-price"><?= sv_esc($rp['price'] > 0 ? 'R$ ' . number_format($rp['price'], 2, ',', '.') : 'Preço sob consulta') ?></div>
                    <div class="card-actions">
                        <a class="btn btn-secondary card-link" href="<?= sv_esc($rUrl) ?>">Ver detalhes</a>
                        <button class="buy-button" type="button" data-product="<?= sv_esc($rPayload) ?>">Comprar</button>
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

        document.getElementById('buy-now').addEventListener('click', function () {
            addToCart(product);
            window.location.href = '/carrinho.php';
        });

        document.querySelectorAll('.buy-button[data-product]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                try {
                    var p = JSON.parse(decodeURIComponent(this.dataset.product));
                    addToCart(p);
                    window.location.href = '/carrinho.php';
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
</body>
</html>
