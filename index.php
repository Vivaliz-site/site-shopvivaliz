<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap-env.php';

// Configuração Dinâmica de Ambiente
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'dev.shopvivaliz.com.br';
define('BASE_URL', $scheme . '://' . $host);
define('APP_NAME', 'ShopVivaliz');
require_once __DIR__ . '/includes/product-price-enrich.php';

function sv_home_esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function sv_home_default_image(): string
{
    return '/images/logo-vivaliz-square.png';
}

function sv_home_money(float $value): string
{
    return $value > 0 ? 'R$ ' . number_format($value, 2, ',', '.') : 'Consulte o valor';
}

function sv_home_product_url(array $product): string
{
    return '/produto?' . http_build_query([
        'sku' => (string)($product['sku'] ?? ''),
        'name' => (string)($product['name'] ?? ''),
        'image' => (string)($product['image_url'] ?? ''),
        'price' => (string)($product['price'] ?? 0),
        'olist_product_id' => (string)($product['olist_product_id'] ?? ''),
    ]);
}

function sv_home_contact_url(array $product): string
{
    return '/contato?' . http_build_query([
        'sku' => (string)($product['sku'] ?? ''),
        'produto' => (string)($product['name'] ?? ''),
    ]);
}

function sv_home_featured_products(int $limit = 8): array
{
    $jsonPath = __DIR__ . '/api/catalog/fallback-products.json';
    if (!is_file($jsonPath) || !is_readable($jsonPath)) {
        return [];
    }

    $decoded = json_decode((string)file_get_contents($jsonPath), true);
    if (!is_array($decoded)) {
        return [];
    }

    $products = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }

        $image = trim((string)($row['image_url'] ?? ''));
        if ($image === '') {
            continue;
        }

        $products[] = [
            'sku' => trim((string)($row['sku'] ?? (string)($row['id'] ?? ''))),
            'name' => trim((string)($row['name'] ?? 'Produto Vivaliz')),
            'image_url' => $image,
            'price' => (float)($row['price'] ?? 0),
            'stock' => (int)($row['stock'] ?? 0),
            'olist_product_id' => (string)($row['olist_product_id'] ?? $row['id'] ?? ''),
            'category' => trim((string)($row['category'] ?? '')),
            'slug' => trim((string)($row['slug'] ?? '')),
        ];

        if (count($products) >= $limit) {
            break;
        }
    }

    return svp_enrich_products($products);
}

function sv_home_catalog_count(): int
{
    $jsonPath = __DIR__ . '/api/catalog/fallback-products.json';
    if (!is_file($jsonPath) || !is_readable($jsonPath)) {
        return 0;
    }

    $decoded = json_decode((string)file_get_contents($jsonPath), true);
    return is_array($decoded) ? count($decoded) : 0;
}

function sv_home_banners(): array
{
    return [
        [
            'eyebrow' => 'Vitrine Vivaliz',
            'title' => 'Rodizios, ferragens e utilidades com visual mais claro.',
            'text' => 'Uma home pensada para destacar produtos reais, leitura rápida no celular e navegação direta até a compra.',
            'primary' => ['label' => 'Explorar catálogo', 'href' => '/catalogo'],
            'secondary' => ['label' => 'Falar com vendas', 'href' => '/contato'],
        ],
        [
            'eyebrow' => 'Compra assistida',
            'title' => 'Atendimento comercial rápido para dúvidas, prazos e orçamento.',
            'text' => 'Quando precisar confirmar compatibilidade, quantidade ou disponibilidade, a equipe da Vivaliz entra no fluxo sem atrito.',
            'primary' => ['label' => 'Ver produtos', 'href' => '/catalogo'],
            'secondary' => ['label' => 'Abrir contato', 'href' => '/contato'],
        ],
        [
            'eyebrow' => 'Entrega nacional',
            'title' => 'Mais confiança visual para comprar de qualquer lugar do Brasil.',
            'text' => 'Cards organizados, identidade consistente e acesso rápido ao carrinho para acelerar a jornada em desktop e mobile.',
            'primary' => ['label' => 'Ir ao carrinho', 'href' => '/carrinho'],
            'secondary' => ['label' => 'Conhecer a marca', 'href' => '/sobre'],
        ],
    ];
}

function sv_home_category_icon(string $category): string
{
    // Mapeia categorias para classes CSS ou ícones SVG
    $map = [
        'ferrament' => 'category-tools',
        'rodízio' => 'category-wheels',
        'rodizio' => 'category-wheels',
        'jardim' => 'category-garden',
        'floreira' => 'category-plants',
        'banheiro' => 'category-bathroom',
        'cozinha' => 'category-kitchen',
        'automotiv' => 'category-auto',
        'elétric' => 'category-electric',
        'eletric' => 'category-electric',
        'cadeado' => 'category-locks',
        'segurança' => 'category-security',
        'seguranca' => 'category-security',
        'armário' => 'category-storage',
        'armario' => 'category-storage',
        'organiza' => 'category-storage',
        'fixação' => 'category-hardware',
        'fixacao' => 'category-hardware',
        'ferragem' => 'category-hardware',
        'caixa' => 'category-boxes',
        'limpeza' => 'category-cleaning',
        'utilidade' => 'category-utilities',
        'pintura' => 'category-paint',
        'construção' => 'category-construction',
        'construcao' => 'category-construction',
        'pet' => 'category-pets',
    ];
    foreach ($map as $needle => $icon_class) {
        if (stripos($category, $needle) !== false) {
            return $icon_class;
        }
    }
    return 'category-default';
}

function sv_home_top_categories(int $limit = 8): array
{
    $jsonPath = __DIR__ . '/api/catalog/fallback-products.json';
    if (!is_file($jsonPath) || !is_readable($jsonPath)) {
        return [];
    }

    $decoded = json_decode((string)file_get_contents($jsonPath), true);
    if (!is_array($decoded)) {
        return [];
    }

    $counts = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $category = trim((string)($row['category'] ?? ''));
        if ($category === '') {
            continue;
        }
        $counts[$category] = ($counts[$category] ?? 0) + 1;
    }

    arsort($counts);
    $result = [];
    foreach ($counts as $category => $count) {
        $result[] = [
            'name' => $category,
            'count' => $count,
            'icon' => sv_home_category_icon($category),
            'href' => '/catalogo?categoria=' . rawurlencode($category),
        ];
        if (count($result) >= $limit) {
            break;
        }
    }

    return $result;
}

$featuredProducts = sv_home_featured_products(8);
$featuredProductsCount = count($featuredProducts);
$catalogCount = sv_home_catalog_count();
$heroBanners = sv_home_banners();
$homeCategories = sv_home_top_categories(10);
$svNavCurrent = '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Vivaliz - Loja online com produtos de qualidade. Rodízios, ferragens, utilidades e muito mais. Compre com segurança.">
    <meta name="theme-color" content="#173B63">
    <meta property="og:title" content="Vivaliz | Loja Online">
    <meta property="og:description" content="Catálogo com produtos de qualidade. Compre online com entrega rápida.">
    <meta property="og:image" content="/images/logo-vivaliz-square.png">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://shopvivaliz.com.br/">
    <meta property="og:site_name" content="ShopVivaliz">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Vivaliz | Loja Online">
    <meta name="twitter:description" content="Catálogo com produtos de qualidade. Compre online com entrega rápida.">
    <link rel="canonical" href="https://shopvivaliz.com.br/">
    <link rel="icon" href="/favicon.ico" type="image/x-icon">

    <title>Vivaliz | Loja Online</title>

    <link rel="stylesheet" href="/css/style.css?v=2026-07-10-1100-FORCE">
    <link rel="stylesheet" href="/css/category-images.css?v=2026-07-10-1100-FORCE">
    <link rel="stylesheet" href="/css/visual-enhancements.css?v=2026-07-10-1100-FORCE">
    <link rel="stylesheet" href="/css/visual-improvements-2026.css?v=2026-07-10-1100-FORCE">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@graph": [
        {
          "@type": "WebSite",
          "name": "Vivaliz",
          "url": "https://shopvivaliz.com.br",
          "potentialAction": {
            "@type": "SearchAction",
            "target": {
              "@type": "EntryPoint",
              "urlTemplate": "https://shopvivaliz.com.br/catalogo?busca={search_term_string}"
            },
            "query-input": "required name=search_term_string"
          }
        },
        {
          "@type": "Store",
          "name": "Vivaliz",
          "url": "https://shopvivaliz.com.br",
          "description": "Loja online com produtos de qualidade. Rodízios, ferragens, utilidades e muito mais.",
          "priceRange": "R$",
          "currenciesAccepted": "BRL",
          "paymentAccepted": "PIX, Cartão de Crédito, Boleto",
          "areaServed": "BR"
        }
      ]
    }
    </script>
</head>
<body>
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-content">
                <p class="eyebrow hero-kicker">
                    🛍️ Loja oficial Vivaliz
                </p>
                <h1>Produtos que <span>você precisa</span>,<br>entrega para todo o Brasil</h1>
                <p>Rodízios, ferragens, utilidades domésticas e itens para casa com catálogo organizado, atendimento rápido e navegação simples no celular.</p>

                <div class="cta-buttons hero-cta">
                    <a href="/catalogo" class="btn btn-hero-primary">
                        Ver catálogo completo
                    </a>
                    <a href="/carrinho" class="btn btn-hero-secondary">
                        🛒 Meu Carrinho
                    </a>
                </div>

                <div class="hero-trust">
                    <div class="hero-trust-item"><span>🔒</span> Compra segura</div>
                    <div class="hero-trust-item"><span>🚚</span> Entrega para todo Brasil</div>
                    <div class="hero-trust-item"><span>⚡</span> PIX com aprovação imediata</div>
                    <div class="hero-trust-item"><span>↩️</span> 30 dias para troca</div>
                </div>
            </div>
        </div>
    </section>

    <section class="hero-carousel-section">
        <div class="container">
            <div class="hero-carousel" id="hero-carousel" aria-label="Banners em destaque">
                <div class="hero-carousel-track">
                    <?php foreach ($heroBanners as $index => $banner): ?>
                        <article class="hero-slide<?= $index === 0 ? ' is-active' : '' ?>" data-slide="<?= $index ?>">
                            <span class="hero-slide-eyebrow"><?= sv_home_esc($banner['eyebrow']) ?></span>
                            <h2><?= sv_home_esc($banner['title']) ?></h2>
                            <p><?= sv_home_esc($banner['text']) ?></p>
                            <div class="hero-slide-actions">
                                <a href="<?= sv_home_esc($banner['primary']['href']) ?>" class="btn btn-primary"><?= sv_home_esc($banner['primary']['label']) ?></a>
                                <a href="<?= sv_home_esc($banner['secondary']['href']) ?>" class="btn btn-secondary"><?= sv_home_esc($banner['secondary']['label']) ?></a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="hero-carousel-controls" aria-label="Controles do banner">
                    <button type="button" class="hero-carousel-arrow" data-dir="-1" aria-label="Banner anterior">‹</button>
                    <div class="hero-carousel-dots">
                        <?php foreach ($heroBanners as $index => $banner): ?>
                            <button type="button" class="hero-carousel-dot<?= $index === 0 ? ' is-active' : '' ?>" data-dot="<?= $index ?>" aria-label="Ir para banner <?= $index + 1 ?>"></button>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="hero-carousel-arrow" data-dir="1" aria-label="Próximo banner">›</button>
                </div>
            </div>
        </div>
    </section>

    <section class="home-categories home-products">
        <div class="container">
            <div class="section-heading">
                <div>
                    <h2>Categorias em destaque</h2>
                    <p class="muted">Navegue por linhas reais do catálogo com acesso rápido.</p>
                </div>
                <a href="/catalogo" class="btn btn-secondary">Ver catálogo</a>
            </div>
            <?php if ($homeCategories): ?>
                <div class="home-scroller" data-scroller>
                    <button type="button" class="home-scroller-arrow" data-dir="-1" aria-label="Categorias anteriores">‹</button>
                    <div class="home-scroller-track categories-track">
                        <?php foreach ($homeCategories as $category): ?>
                            <a class="category-slide" href="<?= sv_home_esc($category['href']) ?>">
                                <div class="category-slide-image-wrapper">
                                    <div class="category-slide-icon <?= sv_home_esc($category['icon']) ?>" aria-hidden="true"></div>
                                </div>
                                <strong><?= sv_home_esc($category['name']) ?></strong>
                                <span class="category-slide-count"><?= (int)$category['count'] ?> itens</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="home-scroller-arrow" data-dir="1" aria-label="Próximas categorias">›</button>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Produtos em destaque -->
    <section class="home-products">
        <div class="container">
            <div class="section-heading">
                <div>
                    <h2>Catálogo em destaque</h2>
                    <p class="muted">Seleção com imagens reais e acesso rápido às linhas mais procuradas.</p>
                </div>
                <a href="/catalogo" class="btn btn-secondary">Ver todos</a>
            </div>
            <div id="catalog-status" class="status-line"><?= $catalogCount > 0 ? $catalogCount . ' produtos disponíveis no catálogo.' : 'Explore nossas linhas e fale com a equipe para atendimento comercial.' ?></div>
            <?php if ($featuredProducts): ?>
                <div class="home-scroller" data-scroller>
                    <button type="button" class="home-scroller-arrow" data-dir="-1" aria-label="Produtos anteriores">‹</button>
                    <div class="home-scroller-track products-track" id="product-grid">
                        <?php foreach ($featuredProducts as $product): ?>
                            <?php
                            $image      = $product['image_url'] !== '' ? $product['image_url'] : sv_home_default_image();
                            $pSlug      = $product['slug'] ?? '';
                            $productUrl = $pSlug !== '' ? '/produto/' . $pSlug : sv_home_product_url($product);
                            $contactUrl = sv_home_contact_url($product);
                            $stock      = (int)($product['stock'] ?? 0);
                            $hasPrice   = (float)($product['price'] ?? 0) > 0 && $stock > 0;
                            $payload    = rawurlencode(json_encode([
                                'sku'              => $product['sku'],
                                'name'             => $product['name'],
                                'image_url'        => $image,
                                'price'            => $product['price'],
                                'olist_product_id' => $product['olist_product_id'],
                                'stock'            => $stock,
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                            ?>
                            <article class="product-card<?= $stock <= 0 ? ' is-out-of-stock' : '' ?>" data-sku="<?= sv_home_esc($product['sku']) ?>">
                                <a class="product-image" href="<?= sv_home_esc($productUrl) ?>">
                                    <img src="<?= sv_home_esc($image) ?>" alt="<?= sv_home_esc($product['name']) ?>" loading="lazy" onerror="this.src='<?= sv_home_default_image() ?>'">
                                    <?php if ($stock <= 0): ?><span class="out-of-stock-badge">Esgotado</span><?php endif; ?>
                                </a>
                                <div class="product-info">
                                    <?php if (!empty($product['category'])): ?>
                                        <div class="product-category"><?= sv_home_esc($product['category']) ?></div>
                                    <?php endif; ?>
                                    <h2><?= sv_home_esc($product['name']) ?></h2>
                                    <div class="product-price"><?= sv_home_esc(sv_home_money((float)$product['price'])) ?></div>
                                    <div class="card-actions">
                                        <a class="btn btn-secondary card-link" href="<?= sv_home_esc($productUrl) ?>">Ver detalhes</a>
                                        <?php if ($hasPrice): ?>
                                            <button class="buy-button" type="button" data-product="<?= sv_home_esc($payload) ?>">Comprar agora</button>
                                        <?php elseif ($stock <= 0): ?>
                                            <button class="btn btn-disabled card-link" type="button" disabled>Esgotado</button>
                                        <?php else: ?>
                                            <a class="btn btn-primary card-link" href="<?= sv_home_esc($contactUrl) ?>">Falar com vendas</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="home-scroller-arrow" data-dir="1" aria-label="Próximos produtos">›</button>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Footer -->
    <?php include __DIR__ . '/includes/footer.php'; ?>

    <script src="/autodev/client.js"></script>
    <script src="/js/catalog.js"></script>
    <script>
    (function () {
        var root = document.getElementById('hero-carousel');
        if (!root) return;
        var slides = Array.prototype.slice.call(root.querySelectorAll('.hero-slide'));
        var dots = Array.prototype.slice.call(root.querySelectorAll('.hero-carousel-dot'));
        var arrows = Array.prototype.slice.call(root.querySelectorAll('.hero-carousel-arrow'));
        var current = 0;
        var timer = null;

        function show(index) {
            current = (index + slides.length) % slides.length;
            slides.forEach(function (slide, slideIndex) {
                slide.classList.toggle('is-active', slideIndex === current);
            });
            dots.forEach(function (dot, dotIndex) {
                dot.classList.toggle('is-active', dotIndex === current);
            });
        }

        function restart() {
            clearInterval(timer);
            timer = setInterval(function () {
                show(current + 1);
            }, 5000);
        }

        dots.forEach(function (dot, index) {
            dot.addEventListener('click', function () {
                show(index);
                restart();
            });
        });

        arrows.forEach(function (arrow) {
            arrow.addEventListener('click', function () {
                show(current + Number(arrow.getAttribute('data-dir') || '1'));
                restart();
            });
        });

        show(0);
        restart();
    })();

    (function () {
        var scrollers = document.querySelectorAll('[data-scroller]');
        scrollers.forEach(function (wrap) {
            var track = wrap.querySelector('.home-scroller-track');
            if (!track) return;
            wrap.querySelectorAll('.home-scroller-arrow').forEach(function (arrow) {
                arrow.addEventListener('click', function () {
                    var dir = Number(arrow.getAttribute('data-dir') || '1');
                    track.scrollBy({ left: dir * Math.max(260, track.clientWidth * 0.82), behavior: 'smooth' });
                });
            });
        });
    })();
    </script>
</body>
</html>
