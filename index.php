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
            'image_url' => trim((string)($row['image_url'] ?? '/favicon.ico')) ?: '/favicon.ico',
            'price' => (float)($row['price'] ?? 0),
            'images_count' => (int)($row['images_count'] ?? 0),
            'olist_product_id' => (string)($row['olist_product_id'] ?? ''),
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
        <div class="container">
            <div class="navbar-brand">
                <h1><?php echo APP_NAME; ?></h1>
                <span class="version">v<?php echo APP_VERSION; ?></span>
            </div>
            <div class="navbar-menu">
                <a href="/">Home</a>
                <a href="/catalogo">Catálogo</a>
                <a href="/admin/">Admin</a>
                <a href="/admin/monitor/">Monitor</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-content">
                <p class="eyebrow">Loja em preparação final</p>
                <h1>Produtos selecionados para comprar com confiança</h1>
                <p>Catálogo ShopVivaliz sincronizado com Tiny/Olist, imagens vinculadas e vitrine pronta para iniciar as vendas.</p>

                <div class="cta-buttons">
                    <a href="/catalogo" class="btn btn-primary">Ver Catálogo</a>
                    <a href="/admin/monitor/" class="btn btn-secondary">Acessar Monitor</a>
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
                    <p class="muted">Produtos carregados do banco ou do relatório validado de imagens.</p>
                </div>
                <a href="/catalogo" class="btn btn-secondary">Ver todos</a>
            </div>
            <div id="catalog-status" class="status-line"><?= count($featuredProducts) > 0 ? count($featuredProducts) . ' produtos em destaque carregados.' : 'Nenhum produto disponível no momento.' ?></div>
            <div class="product-grid" id="product-grid">
                <?php foreach ($featuredProducts as $product): ?>
                    <?php
                    $image = $product['image_url'] !== '' ? $product['image_url'] : '/favicon.ico';
                    $payload = rawurlencode(json_encode([
                        'sku' => $product['sku'],
                        'name' => $product['name'],
                        'image_url' => $image,
                        'price' => $product['price'],
                        'olist_product_id' => $product['olist_product_id'],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    ?>
                    <article class="product-card">
                        <a class="product-image" href="<?= sv_home_esc($image) ?>" target="_blank" rel="noreferrer">
                            <img src="<?= sv_home_esc($image) ?>" alt="<?= sv_home_esc($product['name']) ?>" loading="lazy" onerror="this.src='/favicon.ico'">
                        </a>
                        <div class="product-info">
                            <div class="product-sku"><?= sv_home_esc($product['sku']) ?></div>
                            <h2><?= sv_home_esc($product['name']) ?></h2>
                            <div class="product-meta">
                                <span><?= sv_home_esc(sv_home_money((float)$product['price'])) ?></span>
                                <span><?= (int)$product['images_count'] ?> imagem<?= (int)$product['images_count'] === 1 ? '' : 's' ?></span>
                            </div>
                            <div class="card-actions">
                                <a class="btn btn-secondary card-link" href="<?= sv_home_esc(sv_home_product_url($product)) ?>">Ver detalhes</a>
                                <button class="buy-button" type="button" data-product="<?= sv_home_esc($payload) ?>">Comprar agora</button>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Status dos Agentes -->
    <section class="agents-status">
        <div class="container">
            <h2>Status dos Agentes</h2>

            <div class="agents-grid">
                <div class="agent-card">
                    <h3>Gemini</h3>
                    <p class="role">Arquitetura</p>
                    <div class="status online">Ativo</div>
                    <p>Analisando requisitos e desenha arquitetura das soluções</p>
                </div>

                <div class="agent-card">
                    <h3>Claude</h3>
                    <p class="role">Implementação</p>
                    <div class="status online">Ativo</div>
                    <p>Desenvolve código PHP/JavaScript e otimiza performance</p>
                </div>

                <div class="agent-card">
                    <h3>ChatGPT</h3>
                    <p class="role">Validação</p>
                    <div class="status online">Ativo</div>
                    <p>Valida qualidade, executa testes e garante conformidade</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Estatísticas -->
    <section class="stats">
        <div class="container">
            <div class="stat-item">
                <div class="stat-number" id="tasks-completed">0</div>
                <div class="stat-label">Tarefas Completadas</div>
            </div>
            <div class="stat-item">
                <div class="stat-number" id="uptime">99.9%</div>
                <div class="stat-label">Uptime</div>
            </div>
            <div class="stat-item">
                <div class="stat-number" id="response-time">42ms</div>
                <div class="stat-label">Tempo de Resposta</div>
            </div>
            <div class="stat-item">
                <div class="stat-number" id="products-count">0</div>
                <div class="stat-label">Produtos em Catálogo</div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <p>&copy; 2026 ShopVivaliz. Sistema autônomo de desenvolvimento com IA.</p>
            <p>Versão <?php echo APP_VERSION; ?> | <a href="/admin/monitor/">Admin</a></p>
        </div>
    </footer>

    <!-- Scripts -->
    <script>
        // Carregar dados em tempo real da API Monitor
        document.addEventListener('DOMContentLoaded', function() {
            fetch('/api/monitor/api.php?action=status')
                .then(r => r.json())
                .then(data => {
                    if (data.queue) {
                        document.getElementById('tasks-completed').textContent = data.queue.completed || '0';
                        document.getElementById('uptime').textContent = (data.queue.completion_rate || 0) + '%';
                    }
                })
                .catch(e => console.log('Monitor desconectado (esperado em desenvolvimento)'));
        });
    </script>
    <script src="/autodev/client.js"></script>
    <script src="/js/catalog.js"></script>
</body>
</html>
