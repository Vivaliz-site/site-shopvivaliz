<?php
/**
 * ShopVivaliz - Homepage Pública para Clientes
 * Ecommerce Inteligente com Agentes IA
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

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

require_once __DIR__ . '/api/catalog/image-recovery.php';
require_once __DIR__ . '/api/catalog/ranking.php';

// Versão da aplicação
$svVersion = is_file(__DIR__ . '/config/shopvivaliz-version.php') ? require __DIR__ . '/config/shopvivaliz-version.php' : array();
define('APP_VERSION', (string)($svVersion['version'] ?? '9.2.92'));
define('APP_NAME', 'ShopVivaliz');
if (!defined('BASE_URL')) define('BASE_URL', 'https://dev.shopvivaliz.com.br');

function sv_home_products(int $limit = 8): array
{
    $jsonPath = __DIR__ . '/api/catalog/fallback-products.json';
    if (!is_file($jsonPath) || !is_readable($jsonPath)) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents($jsonPath), true);
    if (!is_array($decoded)) {
        return [];
    }
    $items = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $items[] = [
            'sku' => trim((string)($row['sku'] ?? $row['id'] ?? 'sem-sku')),
            'name' => trim((string)($row['name'] ?? 'Produto ShopVivaliz')),
            'image_url' => trim((string)($row['image_url'] ?? '')) ?: svimg_placeholder_url(),
            'price' => (float)($row['price'] ?? 0),
            'images_count' => (int)($row['images_count'] ?? 0),
            'olist_product_id' => (string)($row['olist_product_id'] ?? ''),
        ];
    }

    $items = array_map('svimg_recover_product', $items);
    $items = svrank_sort_products($items);
    return array_slice($items, 0, $limit);
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
    <meta name="description" content="ShopVivaliz - Sua loja online de qualidade. Produtos variados com entrega rápida e segura.">
    <meta name="theme-color" content="#667eea">
    <title><?php echo APP_NAME; ?> - Sua Loja Online de Confiança</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <link rel="stylesheet" href="/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Banner Slider */
        .banner-slider {
            position: relative;
            width: 100%;
            height: 300px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 0;
            overflow: hidden;
            margin-bottom: 40px;
        }

        .banner-slide {
            display: none;
            width: 100%;
            height: 100%;
            position: absolute;
            top: 0;
            left: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 20px;
        }

        .banner-slide.active {
            display: flex;
        }

        .banner-slide h2 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .banner-slide p {
            font-size: 16px;
            margin-bottom: 20px;
            opacity: 0.95;
        }

        .banner-dots {
            position: absolute;
            bottom: 15px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 8px;
        }

        .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: rgba(255,255,255,0.4);
            cursor: pointer;
            transition: all 0.3s;
        }

        .dot.active {
            background: white;
            width: 30px;
            border-radius: 5px;
        }

        /* Categorias */
        .categories-section {
            padding: 40px 0;
            background: #f9fafb;
        }

        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
        }

        .category-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .category-card:hover {
            border-color: #667eea;
            transform: translateY(-4px);
            box-shadow: 0 4px 12px rgba(102,126,234,0.2);
        }

        .category-icon {
            font-size: 32px;
            margin-bottom: 10px;
        }

        .category-card h3 {
            font-size: 14px;
            color: #1f2937;
            font-weight: 600;
        }

        /* Produtos em Destaque */
        .featured-section {
            padding: 40px 0;
        }

        .section-title {
            font-size: 24px;
            margin-bottom: 30px;
            text-align: center;
            color: #1f2937;
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
        }

        .product-card {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.3s;
        }

        .product-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
        }

        .product-image {
            width: 100%;
            height: 200px;
            background: linear-gradient(135deg, #f0f0f0 0%, #e0e0e0 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 64px;
            color: #999;
        }

        .product-info {
            padding: 16px;
        }

        .product-name {
            font-size: 14px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 8px;
            height: 2.8em;
            overflow: hidden;
        }

        .product-price {
            font-size: 18px;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 12px;
        }

        .product-rating {
            font-size: 12px;
            color: #f59e0b;
            margin-bottom: 12px;
        }

        .product-btn {
            width: 100%;
            padding: 10px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }

        .product-btn:hover {
            background: #764ba2;
        }

        /* Promoção */
        .promo-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            border-radius: 8px;
            text-align: center;
            margin: 40px 0;
        }

        .promo-banner h2 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .promo-banner p {
            font-size: 16px;
            margin-bottom: 20px;
        }

        /* Newsletter */
        .newsletter-section {
            background: #f9fafb;
            padding: 40px 0;
            margin-top: 40px;
        }

        .newsletter-content {
            max-width: 500px;
            margin: 0 auto;
            text-align: center;
        }

        .newsletter-content h2 {
            font-size: 24px;
            margin-bottom: 10px;
        }

        .newsletter-content p {
            margin-bottom: 20px;
            color: #666;
        }

        .newsletter-form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .newsletter-form input {
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
        }

        .newsletter-form button {
            padding: 12px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .newsletter-form button:hover {
            background: #764ba2;
        }

        /* Busca */
        .search-bar {
            display: flex;
            gap: 8px;
            margin: 20px 0;
        }

        .search-bar input {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
        }

        .search-bar button {
            padding: 12px 24px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
        }

        /* Responsivo */
        @media (max-width: 767px) {
            .banner-slider {
                height: 200px;
            }

            .banner-slide h2 {
                font-size: 20px;
            }

            .banner-slide p {
                font-size: 14px;
            }

            .categories-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 12px;
            }

            .category-card {
                padding: 12px;
            }

            .category-icon {
                font-size: 24px;
            }

            .products-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .promo-banner {
                padding: 20px;
            }

            .promo-banner h2 {
                font-size: 20px;
            }

            .section-title {
                font-size: 20px;
            }
        }

        @media (min-width: 768px) {
            .banner-slider {
                height: 350px;
            }

            .banner-slide h2 {
                font-size: 32px;
            }

            .categories-grid {
                grid-template-columns: repeat(5, 1fr);
            }

            .products-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .newsletter-form {
                flex-direction: row;
            }

            .newsletter-form input {
                flex: 1;
            }
        }

        @media (min-width: 1025px) {
            .banner-slider {
                height: 400px;
                margin-bottom: 50px;
            }

            .banner-slide h2 {
                font-size: 42px;
            }

            .categories-grid {
                grid-template-columns: repeat(6, 1fr);
            }

            .products-grid {
                grid-template-columns: repeat(5, 1fr);
            }

            .section-title {
                font-size: 28px;
            }
        }
    </style>
</head>
<body>
    <!-- Navegação -->
    <nav class="navbar">
        <div class="container">
            <div class="navbar-brand">
                <a href="/" style="display: flex; align-items: center; text-decoration: none;">
                    <img src="/images/logo.svg" alt="ShopVivaliz" style="height: 50px; width: auto;">
                </a>
            </div>
            <button class="menu-toggle" id="menuToggle">☰</button>
            <div class="navbar-menu" id="navMenu">
                <a href="/">Home</a>
                <a href="/catalogo">Catálogo</a>
                <a href="/sobre">Sobre</a>
                <a href="/contato">Contato</a>
                <a href="/carrinho">🛒 Carrinho</a>
                <a href="/admin/">Admin</a>
                <a href="/admin/monitor/">Monitor</a>
            </div>
        </div>
    </nav>

    <!-- Banner Slider -->
    <section class="banner-slider">
        <div class="banner-slide active">
            <div>
                <h2>Bem-vindo ao ShopVivaliz</h2>
                <p>Produtos de qualidade com entrega rápida</p>
                <a href="/catalogo" class="btn btn-primary" style="display: inline-block;">Comprar Agora</a>
            </div>
        </div>
        <div class="banner-slide">
            <div>
                <h2>Promoção 50% OFF</h2>
                <p>Em produtos selecionados esta semana</p>
                <a href="/catalogo" class="btn btn-primary" style="display: inline-block;">Ver Promoções</a>
            </div>
        </div>
        <div class="banner-slide">
            <div>
                <h2>Frete Grátis</h2>
                <p>Compras acima de R$ 100</p>
                <a href="/catalogo" class="btn btn-primary" style="display: inline-block;">Aproveitar</a>
            </div>
        </div>
        <div class="banner-dots">
            <span class="dot active" onclick="currentSlide(0)"></span>
            <span class="dot" onclick="currentSlide(1)"></span>
            <span class="dot" onclick="currentSlide(2)"></span>
        </div>
    </section>

    <!-- Busca -->
    <section class="container">
        <div class="search-bar">
            <input type="text" placeholder="Buscar produtos...">
            <button>Buscar</button>
        </div>
    </section>

    <!-- Categorias -->
    <section class="categories-section">
        <div class="container">
            <h2 style="text-align: center; margin-bottom: 30px;">Categorias</h2>
            <div class="categories-grid">
                <div class="category-card">
                    <div class="category-icon">👕</div>
                    <h3>Roupas</h3>
                </div>
                <div class="category-card">
                    <div class="category-icon">👟</div>
                    <h3>Calçados</h3>
                </div>
                <div class="category-card">
                    <div class="category-icon">💎</div>
                    <h3>Acessórios</h3>
                </div>
                <div class="category-card">
                    <div class="category-icon">🏠</div>
                    <h3>Casa</h3>
                </div>
                <div class="category-card">
                    <div class="category-icon">⚽</div>
                    <h3>Esportes</h3>
                </div>
                <div class="category-card">
                    <div class="category-icon">📱</div>
                    <h3>Eletrônicos</h3>
                </div>
            </div>
        </div>
    </section>

    <!-- Produtos em Destaque -->
    <section class="featured-section">
        <div class="container">
            <h2 class="section-title">Produtos em Destaque</h2>
            <div class="products-grid">
                <?php if ($featuredProducts): ?>
                    <?php foreach ($featuredProducts as $product): ?>
                        <div class="product-card">
                            <div class="product-image">
                                <img src="<?php echo sv_home_esc($product['image_url']); ?>" alt="<?php echo sv_home_esc($product['name']); ?>" style="width: 100%; height: 100%; object-fit: cover;" loading="lazy" onerror="this.src='<?php echo sv_home_esc(svimg_placeholder_url()); ?>'">
                            </div>
                            <div class="product-info">
                                <div class="product-name"><?php echo sv_home_esc($product['name']); ?></div>
                                <div class="product-price"><?php echo sv_home_esc(sv_home_money((float)$product['price'])); ?></div>
                                <div class="product-rating"><?php echo (int)$product['images_count']; ?> imagem<?php echo (int)$product['images_count'] === 1 ? '' : 'ns'; ?> | SKU <?php echo sv_home_esc($product['sku']); ?></div>
                                <a class="product-btn" href="<?php echo sv_home_esc(sv_home_product_url($product)); ?>" style="display: inline-block; text-align: center; text-decoration: none;">Ver Produto</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="product-card">
                        <div class="product-info">
                            <div class="product-name">Catálogo em preparação</div>
                            <div class="product-price">Novos produtos em breve</div>
                            <div class="product-rating">Aguardando sincronização final</div>
                            <a class="product-btn" href="/catalogo" style="display: inline-block; text-align: center; text-decoration: none;">Ir para o catálogo</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Promoção -->
    <section class="promo-banner">
        <div class="container">
            <h2>Aproveite Nossa Promoção Especial</h2>
            <p>Ganhe 20% de desconto na primeira compra com cupom: BEMVINDO20</p>
            <a href="/catalogo" class="btn btn-primary">Usar Cupom</a>
        </div>
    </section>

    <!-- Newsletter -->
    <section class="newsletter-section">
        <div class="container">
            <div class="newsletter-content">
                <h2>Fique por Dentro</h2>
                <p>Receba ofertas exclusivas e novidades do ShopVivaliz</p>
                <form class="newsletter-form">
                    <input type="email" placeholder="Seu e-mail" required>
                    <button type="submit">Se Inscrever</button>
                </form>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 30px; margin-bottom: 30px; text-align: left;">
                <div>
                    <h3 style="margin-bottom: 15px; color: white;">Sobre</h3>
                    <ul style="list-style: none; padding: 0;">
                        <li><a href="/sobre" style="color: #ccc; text-decoration: none;">Sobre Nós</a></li>
                        <li><a href="/carreiras" style="color: #ccc; text-decoration: none;">Carreiras</a></li>
                        <li><a href="/blog" style="color: #ccc; text-decoration: none;">Blog</a></li>
                    </ul>
                </div>
                <div>
                    <h3 style="margin-bottom: 15px; color: white;">Compras</h3>
                    <ul style="list-style: none; padding: 0;">
                        <li><a href="/catalogo" style="color: #ccc; text-decoration: none;">Catálogo</a></li>
                        <li><a href="/promocoes" style="color: #ccc; text-decoration: none;">Promoções</a></li>
                        <li><a href="/rastreamento" style="color: #ccc; text-decoration: none;">Rastreamento</a></li>
                    </ul>
                </div>
                <div>
                    <h3 style="margin-bottom: 15px; color: white;">Suporte</h3>
                    <ul style="list-style: none; padding: 0;">
                        <li><a href="/contato" style="color: #ccc; text-decoration: none;">Contato</a></li>
                        <li><a href="/faq" style="color: #ccc; text-decoration: none;">FAQ</a></li>
                        <li><a href="/politica-privacidade" style="color: #ccc; text-decoration: none;">Privacidade</a></li>
                    </ul>
                </div>
            </div>
            <div style="border-top: 1px solid #444; padding-top: 20px; text-align: center;">
                <p>&copy; 2026 ShopVivaliz. Todos os direitos reservados.</p>
                <p style="font-size: 12px; margin-top: 10px;">Desenvolvido com IA | Entrega Rápida | Compra Segura</p>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script>
        // Menu toggle mobile
        const menuToggle = document.getElementById('menuToggle');
        const navMenu = document.getElementById('navMenu');

        if (menuToggle) {
            menuToggle.addEventListener('click', function() {
                navMenu.classList.toggle('active');
            });

            navMenu.querySelectorAll('a').forEach(link => {
                link.addEventListener('click', function() {
                    navMenu.classList.remove('active');
                });
            });
        }

        // Banner Slider
        let currentSlideIndex = 0;
        const slides = document.querySelectorAll('.banner-slide');
        const dots = document.querySelectorAll('.dot');

        function showSlide(n) {
            slides.forEach(slide => slide.classList.remove('active'));
            dots.forEach(dot => dot.classList.remove('active'));

            if (n >= slides.length) currentSlideIndex = 0;
            if (n < 0) currentSlideIndex = slides.length - 1;

            slides[currentSlideIndex].classList.add('active');
            dots[currentSlideIndex].classList.add('active');
        }

        function currentSlide(n) {
            currentSlideIndex = n;
            showSlide(currentSlideIndex);
        }

        // Auto-rotate banners
        setInterval(() => {
            currentSlideIndex++;
            showSlide(currentSlideIndex);
        }, 5000);

        // Produtos - adicionar ao carrinho
        document.querySelectorAll('.product-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                alert('Produto adicionado ao carrinho!');
            });
        });

        // Categorias
        document.querySelectorAll('.category-card').forEach(card => {
            card.addEventListener('click', function() {
                alert('Redirecionando para categoria...');
            });
        });
    </script>
    <script src="/autodev/client.js"></script>
    <script src="/js/catalog.js"></script>
</body>
</html>
