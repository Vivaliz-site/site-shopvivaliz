<?php
/**
 * ShopVivaliz - Ecommerce Autônomo com Agentes IA
 * Homepage Principal
 */

// Inicializar
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Cabeçalhos de segurança
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: text/html; charset=UTF-8');

// Mercado Livre OAuth callback — ML_REDIRECT_URI aponta para a raiz do domínio
if (isset($_GET['code']) || isset($_GET['error'])) {
    require_once __DIR__ . '/api/ml/callback.php';
    exit;
}

// Versão da aplicação
$svVersion = is_file(__DIR__ . '/config/shopvivaliz-version.php') ? require __DIR__ . '/config/shopvivaliz-version.php' : array();
define('APP_VERSION', (string)($svVersion['version'] ?? '9.2.92'));
define('APP_NAME', 'ShopVivaliz');
if (!defined('BASE_URL')) define('BASE_URL', 'https://dev.shopvivaliz.com.br');

function sv_home_products(int $limit = 8): array
{
    $jsonPath = __DIR__ . '/api/catalog/fallback-products.json';
    if (!is_file($jsonPath) || !is_readable($jsonPath)) return [];
    $decoded = json_decode((string)file_get_contents($jsonPath), true);
    if (!is_array($decoded)) return [];

    // V16: ordena por commerce_score se disponível
    $rows = array_filter($decoded, 'is_array');
    usort($rows, function($a, $b) {
        return ($b['commerce_score'] ?? $b['quality_score'] ?? 0) <=> ($a['commerce_score'] ?? $a['quality_score'] ?? 0);
    });

    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'sku'              => trim((string)($row['sku'] ?? $row['id'] ?? 'sem-sku')),
            'name'             => trim((string)($row['name'] ?? 'Produto Vivaliz')),
            'image_url'        => trim((string)($row['image_url'] ?? '/favicon.ico')) ?: '/favicon.ico',
            'price'            => (float)($row['price'] ?? 0),
            'images_count'     => (int)($row['images_count'] ?? 0),
            'olist_product_id' => (string)($row['olist_product_id'] ?? ''),
            'slug'             => trim((string)($row['slug'] ?? '')),
            'category'         => trim((string)($row['category'] ?? '')),
        ];
        if (count($items) >= $limit) {
            break;
        }
    }
    return $items;
}

function sv_home_esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function sv_home_money(float $value): string
{
    return $value > 0 ? 'R$ ' . number_format($value, 2, ',', '.') : 'Preço sob consulta';
}

function sv_home_product_url(array $product): string
{
    return '/produto?' . http_build_query([
        'sku' => $product['sku'],
        'name' => $product['name'],
        'image' => $product['image_url'],
        'price' => (string)$product['price'],
        'olist_product_id' => $product['olist_product_id'],
    ]);
}

$featuredProducts = sv_home_products();

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
    <!-- Navegação -->
    <nav class="navbar">
        <div class="container nav-inner">
            <a class="brand-link" href="/">
                <img src="/images/logo-vivaliz.png" alt="Vivaliz" class="brand-logo-img" onerror="this.src='/images/logo.svg'">
            </a>
            <div class="navbar-menu">
                <a href="/catalogo">Catálogo</a>
                <a href="/sobre">Sobre</a>
                <a href="/carrinho.php" class="nav-cart" id="nav-cart-link">
                    🛒 Carrinho <span class="cart-badge" id="nav-cart-count">0</span>
                </a>
            </div>
        </div>
    </nav>

    <!-- Banner Slider -->
    <section class="banner-slider" aria-label="Promoções em destaque">
        <div class="slider-track" id="sliderTrack">
            <!-- Slide 1 -->
            <div class="slide slide-1">
                <div class="container">
                    <div class="slide-content">
                        <p class="slide-eyebrow">🛍️ Loja oficial Vivaliz</p>
                        <h1>Produtos que <span>você precisa</span>,<br>entrega para todo o Brasil</h1>
                        <p>Rodízios, ferragens, utilidades domésticas, garden e muito mais — <?= count($featuredProducts) > 0 ? '197 produtos' : 'catálogo completo' ?> com qualidade garantida.</p>
                        <div class="slide-actions">
                            <a href="/catalogo" class="btn-slide btn-slide-primary">Ver catálogo</a>
                            <a href="/carrinho.php" class="btn-slide btn-slide-ghost">🛒 Carrinho</a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Slide 2 -->
            <div class="slide slide-2">
                <div class="container">
                    <div class="slide-content">
                        <p class="slide-eyebrow">🔩 Ferragens & Rodízios</p>
                        <h1>Qualidade industrial<br><span>para sua casa</span></h1>
                        <p>Rodízios de alta durabilidade, trilhos, dobradiças e muito mais. Envio imediato para todo Brasil.</p>
                        <div class="slide-actions">
                            <a href="/catalogo?categoria=Rodizios" class="btn-slide btn-slide-primary">Ver rodízios</a>
                            <a href="/catalogo?categoria=Ferragens" class="btn-slide btn-slide-ghost">Ver ferragens</a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Slide 3 -->
            <div class="slide slide-3">
                <div class="container">
                    <div class="slide-content">
                        <p class="slide-eyebrow">⚡ Pagamento instantâneo</p>
                        <h1>PIX com aprovação<br><span>imediata</span></h1>
                        <p>Pague com PIX e tenha aprovação na hora. Cartão em até 12x e boleto bancário disponíveis.</p>
                        <div class="slide-actions">
                            <a href="/catalogo" class="btn-slide btn-slide-primary">Comprar agora</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Dots -->
        <div class="slider-dots" role="tablist" aria-label="Slides">
            <button class="slider-dot active" data-slide="0" role="tab" aria-selected="true" aria-label="Slide 1"></button>
            <button class="slider-dot" data-slide="1" role="tab" aria-selected="false" aria-label="Slide 2"></button>
            <button class="slider-dot" data-slide="2" role="tab" aria-selected="false" aria-label="Slide 3"></button>
        </div>
        <!-- Arrows -->
        <button class="slider-arrow slider-prev" aria-label="Slide anterior">&#8249;</button>
        <button class="slider-arrow slider-next" aria-label="Próximo slide">&#8250;</button>
    </section>

    <!-- Categorias -->
    <section class="categories-section">
        <div class="container">
            <div class="section-heading">
                <div>
                    <h2>Compre por categoria</h2>
                    <p class="muted">Encontre o que precisa rapidamente.</p>
                </div>
                <a href="/catalogo" class="btn btn-secondary">Ver tudo</a>
            </div>
            <div class="categories-grid">
                <a href="/catalogo?categoria=Rodizios" class="category-card">
                    <span class="cat-icon">🔩</span>
                    <strong>Rodízios</strong>
                    <span class="cat-sub">Industrial & doméstico</span>
                </a>
                <a href="/catalogo?categoria=Ferragens" class="category-card">
                    <span class="cat-icon">🔧</span>
                    <strong>Ferragens</strong>
                    <span class="cat-sub">Trilhos, dobradiças</span>
                </a>
                <a href="/catalogo?categoria=Utilidades" class="category-card">
                    <span class="cat-icon">🏠</span>
                    <strong>Utilidades</strong>
                    <span class="cat-sub">Casa & cozinha</span>
                </a>
                <a href="/catalogo?categoria=Garden" class="category-card">
                    <span class="cat-icon">🌱</span>
                    <strong>Garden</strong>
                    <span class="cat-sub">Jardim & exterior</span>
                </a>
                <a href="/catalogo?categoria=Organizacao" class="category-card">
                    <span class="cat-icon">📦</span>
                    <strong>Organização</strong>
                    <span class="cat-sub">Caixas & suportes</span>
                </a>
                <a href="/catalogo" class="category-card cat-all">
                    <span class="cat-icon">🛍️</span>
                    <strong>Ver todos</strong>
                    <span class="cat-sub">197 produtos</span>
                </a>
            </div>
        </div>
    </section>

    <!-- Hero Section (confiança) -->
    <section class="hero hero-trust-bar">
        <div class="container">
            <div class="hero-content">
                <div class="hero-trust">
                    <div class="hero-trust-item"><span>🔒</span> Compra segura</div>
                    <div class="hero-trust-item"><span>🚚</span> Entrega para todo Brasil</div>
                    <div class="hero-trust-item"><span>⚡</span> PIX com aprovação imediata</div>
                    <div class="hero-trust-item"><span>↩️</span> 30 dias para troca</div>
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
                    <p class="muted">Seleção especial de produtos disponíveis agora.</p>
                </div>
                <a href="/catalogo" class="btn btn-secondary">Ver todos</a>
            </div>
            <div id="catalog-status" class="status-line"><?= count($featuredProducts) > 0 ? count($featuredProducts) . ' produtos em destaque carregados.' : 'Nenhum produto disponível no momento.' ?></div>
            <div class="product-grid" id="product-grid">
                <?php foreach ($featuredProducts as $product): ?>
                    <?php
                    $image      = $product['image_url'] !== '' ? $product['image_url'] : '/favicon.ico';
                    $pSlug      = $product['slug'] ?? '';
                    $productUrl = $pSlug !== '' ? '/produto/' . $pSlug : sv_home_product_url($product);
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
                            <img src="<?= sv_home_esc($image) ?>" alt="<?= sv_home_esc($product['name']) ?>" loading="lazy" onerror="this.src='/favicon.ico'">
                        </a>
                        <div class="product-info">
                            <?php if (!empty($product['category'])): ?>
                                <div class="product-category"><?= sv_home_esc($product['category']) ?></div>
                            <?php endif; ?>
                            <h2><?= sv_home_esc($product['name']) ?></h2>
                            <div class="product-price"><?= sv_home_esc(sv_home_money((float)$product['price'])) ?></div>
                            <div class="card-actions">
                                <a class="btn btn-secondary card-link" href="<?= sv_home_esc($productUrl) ?>">Ver detalhes</a>
                                <button class="buy-button" type="button" data-product="<?= sv_home_esc($payload) ?>">Comprar agora</button>
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
    (function(){
        try {
            var cart = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]');
            var count = cart.reduce(function(a,i){ return a+(i.quantity||1); }, 0);
            var badge = document.getElementById('nav-cart-count');
            if (badge) badge.textContent = count > 0 ? count : '';
        } catch(e){}
    })();
    </script>
    <!-- V16: signal tracker — registra views dos cards em destaque -->
    <script>
    (function(){
        document.querySelectorAll('.product-card[data-sku]').forEach(function(card){
            var sku = card.getAttribute('data-sku');
            if(!sku) return;
            fetch('/api/catalog/signal.php',{
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body: JSON.stringify({event:'view', sku: sku})
            }).catch(function(){});
        });
    })();
    </script>
    <script>
    // Banner Slider
    (function(){
        var track = document.getElementById('sliderTrack');
        if (!track) return;
        var dots  = document.querySelectorAll('.slider-dot');
        var total = dots.length;
        var cur   = 0;
        var timer;

        function go(idx) {
            cur = (idx + total) % total;
            track.style.transform = 'translateX(-' + (cur * 100) + '%)';
            dots.forEach(function(d, i) {
                d.classList.toggle('active', i === cur);
                d.setAttribute('aria-selected', i === cur ? 'true' : 'false');
            });
        }

        function next() { go(cur + 1); }
        function prev() { go(cur - 1); }

        function resetTimer() {
            clearInterval(timer);
            timer = setInterval(next, 5000);
        }

        dots.forEach(function(d) {
            d.addEventListener('click', function() {
                go(parseInt(this.getAttribute('data-slide'), 10));
                resetTimer();
            });
        });

        var prevBtn = document.querySelector('.slider-prev');
        var nextBtn = document.querySelector('.slider-next');
        if (prevBtn) prevBtn.addEventListener('click', function(){ prev(); resetTimer(); });
        if (nextBtn) nextBtn.addEventListener('click', function(){ next(); resetTimer(); });

        // Touch swipe
        var startX = 0;
        track.addEventListener('touchstart', function(e){ startX = e.touches[0].clientX; }, {passive:true});
        track.addEventListener('touchend', function(e){
            var dx = e.changedTouches[0].clientX - startX;
            if (Math.abs(dx) > 40) { dx < 0 ? next() : prev(); resetTimer(); }
        }, {passive:true});

        resetTimer();
    })();
    </script>
</body>
</html>
