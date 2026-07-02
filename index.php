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

    <title>Vivaliz | Loja Online</title>

    <link rel="stylesheet" href="/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <!-- Navegação -->
    <nav class="navbar">
        <div class="container nav-inner">
            <a class="brand-link" href="/">
                <span class="brand-logo">V</span>Vivaliz
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

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-content">
                <p class="eyebrow" style="color:#7dd3fc;font-size:13px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;margin:0 0 16px">
                    🛍️ Loja oficial Vivaliz
                </p>
                <h1>Produtos que <span>você precisa</span>,<br>entrega para todo o Brasil</h1>
                <p>Rodízios, ferragens, utilidades domésticas, garden e muito mais — <?= count($featuredProducts) > 0 ? '197 produtos' : 'catálogo completo' ?> com qualidade garantida.</p>

                <div class="cta-buttons" style="margin-top:28px">
                    <a href="/catalogo" class="btn btn-primary" style="background:#fff;color:#1d4ed8;font-size:15px;padding:14px 24px">
                        Ver catálogo completo
                    </a>
                    <a href="/carrinho.php" class="btn" style="background:rgba(255,255,255,0.15);color:#fff;border:1.5px solid rgba(255,255,255,0.35);font-size:15px;padding:14px 24px">
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
</body>
</html>
