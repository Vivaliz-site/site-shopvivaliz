<?php
declare(strict_types=1);

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

$featuredProducts = sv_home_featured_products(8);
$featuredProductsCount = count($featuredProducts);
$catalogCount = sv_home_catalog_count();
$heroBanners = sv_home_banners();
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

    <link rel="stylesheet" href="/css/style.css">
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
                <p class="eyebrow" style="color:#7dd3fc;font-size:13px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;margin:0 0 16px">
                    🛍️ Loja oficial Vivaliz
                </p>
                <h1>Produtos que <span>você precisa</span>,<br>entrega para todo o Brasil</h1>
                <p>Rodízios, ferragens, utilidades domésticas e itens para casa com catálogo organizado, atendimento rápido e navegação simples no celular.</p>

                <div class="cta-buttons" style="margin-top:28px">
                    <a href="/catalogo" class="btn btn-primary" style="background:#fff;color:#1d4ed8;font-size:15px;padding:14px 24px">
                        Ver catálogo completo
                    </a>
                    <a href="/carrinho" class="btn" style="background:rgba(255,255,255,0.15);color:#fff;border:1.5px solid rgba(255,255,255,0.35);font-size:15px;padding:14px 24px">
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
            <div class="product-grid" id="product-grid">
                <?php foreach ($featuredProducts as $product): ?>
                    <?php
                    $image      = $product['image_url'] !== '' ? $product['image_url'] : sv_home_default_image();
                    $pSlug      = $product['slug'] ?? '';
                    $productUrl = $pSlug !== '' ? '/produto/' . $pSlug : sv_home_product_url($product);
                    $contactUrl = sv_home_contact_url($product);
                    $hasPrice   = (float)($product['price'] ?? 0) > 0;
                    $payload    = rawurlencode(json_encode([
                        'sku'              => $product['sku'],
                        'name'             => $product['name'],
                        'image_url'        => $image,
                        'price'            => $product['price'],
                        'olist_product_id' => $product['olist_product_id'],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    ?>
                    <article class="product-card" data-sku="<?= sv_home_esc($product['sku']) ?>">
                        <a class="product-image" href="<?= sv_home_esc($productUrl) ?>">
                            <img src="<?= sv_home_esc($image) ?>" alt="<?= sv_home_esc($product['name']) ?>" loading="lazy" onerror="this.src='<?= sv_home_default_image() ?>'">
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
                                <?php else: ?>
                                    <a class="btn btn-primary card-link" href="<?= sv_home_esc($contactUrl) ?>">Falar com vendas</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="footer-cols">
                <div>
                    <strong>Vivaliz</strong>
                    <p>Qualidade e entrega rápida para todo o Brasil.</p>
                </div>
                <div>
                    <strong>Navegação</strong>
                    <a href="/catalogo">Catálogo</a>
                    <a href="/gamificacao.php">Gamificação</a>
                    <a href="/sobre">Sobre</a>
                    <a href="/contato">Contato</a>
                </div>
                <div>
                    <strong>Atendimento</strong>
                    <a href="/contato">Fale conosco</a>
                    <a href="/faq">Dúvidas frequentes</a>
                    <a href="/politica-privacidade">Privacidade</a>
                </div>
            </div>
            <p class="footer-copy">&copy; 2026 Vivaliz. Todos os direitos reservados.</p>
        </div>
    </footer>

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
    </script>
</body>
</html>
