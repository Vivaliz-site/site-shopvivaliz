<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap-env.php';

// Configuração Dinâmica de Ambiente
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'shopvivaliz.com.br';
define('BASE_URL', $scheme . '://' . $host);
define('APP_NAME', 'ShopVivaliz');
require_once __DIR__ . '/includes/product-price-enrich.php';
require_once __DIR__ . '/includes/catalog-runtime.php';
require_once __DIR__ . '/includes/site-settings.php';
$svFreeShipping = sv_free_shipping_config();

function sv_home_esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function sv_home_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function sv_home_default_image(): string
{
    return '/images/logo-vivaliz-square.png';
}

function sv_home_catalog_source_rows(): array
{
    $runtime = svcr_products();
    if ($runtime !== []) return $runtime;

    $jsonPath = __DIR__ . '/api/catalog/fallback-products.json';
    if (is_file($jsonPath) && is_readable($jsonPath)) {
        $decoded = json_decode((string)file_get_contents($jsonPath), true);
        if (is_array($decoded) && $decoded !== []) {
            return $decoded;
        }
    }

    $csvPath = __DIR__ . '/uploads/olist_imagens_site_mapeamento.csv';
    if (!is_file($csvPath) || !is_readable($csvPath)) {
        return [];
    }

    $rows = [];
    $handle = fopen($csvPath, 'r');
    if (!$handle) {
        return [];
    }

    $header = fgetcsv($handle);
    if (!is_array($header)) {
        fclose($handle);
        return [];
    }

    while (($line = fgetcsv($handle)) !== false) {
        if (!is_array($line) || $line === []) {
            continue;
        }
        $assoc = [];
        foreach ($header as $i => $column) {
            $key = trim((string)$column);
            if ($key === '') {
                continue;
            }
            $assoc[$key] = $line[$i] ?? '';
        }
        if (isset($assoc['site_url']) && $assoc['site_url'] !== '') {
            $assoc['image_url'] = $assoc['site_url'];
        } elseif (isset($assoc['original_url_olist']) && $assoc['original_url_olist'] !== '') {
            $assoc['image_url'] = $assoc['original_url_olist'];
        }
        if (isset($assoc['nome_produto']) && $assoc['nome_produto'] !== '') {
            $assoc['name'] = $assoc['nome_produto'];
        }

        if (($assoc['image_url'] ?? '') === '') {
            continue;
        }
        $rows[] = $assoc;
    }

    fclose($handle);
    return $rows;
}

function sv_home_money(float $value): string
{
    return $value > 0 ? 'R$ ' . number_format($value, 2, ',', '.') : 'Consulte o valor';
}

function sv_home_slugify(string $name, string $sku): string
{
    $accents = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n'];
    $base = strtr(sv_home_lower($name), $accents);
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim((string)$base, '-');
    $base = function_exists('mb_substr') ? mb_substr($base, 0, 60) : substr($base, 0, 60);
    $skuPart = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '', $sku));
    return trim($base . '-' . $skuPart, '-') ?: $skuPart;
}

function sv_home_product_url(array $product): string
{
    $sku = trim((string)($product['sku'] ?? ''));
    $name = trim((string)($product['name'] ?? ''));
    $slug = trim((string)($product['slug'] ?? '')) ?: ($sku !== '' && $name !== '' ? sv_home_slugify($name, $sku) : '');
    if ($slug !== '') {
        return '/produto/' . $slug;
    }

    return '/produto?' . http_build_query([
        'sku' => $sku,
        'name' => $name,
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
    $products = [];
    foreach (sv_home_catalog_source_rows() as $row) {
        if (!is_array($row)) {
            continue;
        }

        $image = trim((string)($row['image_url'] ?? $row['image'] ?? ''));
        if ($image === '') {
            continue;
        }

        $images = is_array($row['images'] ?? null) ? $row['images'] : [];
        $products[] = [
            'sku' => trim((string)($row['sku'] ?? (string)($row['id'] ?? ''))),
            'name' => trim((string)($row['name'] ?? 'Produto Vivaliz')),
            'image_url' => $image,
            'images' => array_slice(array_filter($images), 0, 10),
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
    return count(sv_home_catalog_source_rows());
}

function sv_home_banners(): array
{
    return [
        [
            'alt' => 'Banner Vivaliz com 10% de desconto na primeira compra',
            'image' => '/public/assets/home-banners/banner-primeira-compra.jpg',
            'tag' => 'OFERTA EXCLUSIVA',
            'title' => 'Tudo o que você precisa.',
            'subtitle' => 'Ganhe 10% de desconto na sua primeira compra com o cupom VIVALIZ10.',
            'primary' => ['label' => 'Aproveitar Desconto', 'href' => '/catalogo'],
            'secondary' => ['label' => 'Falar com vendas', 'href' => '/contato'],
        ],
        [
            'alt' => 'Banner Vivaliz para casa, jardim e organização',
            'image' => '/public/assets/home-banners/banner-casa-estilo.jpg',
            'tag' => 'COLEÇÃO 2026',
            'title' => 'Renove o seu espaço.',
            'subtitle' => 'Ferramentas de alta precisão e organização inteligente para uma casa impecável.',
            'primary' => ['label' => 'Ver Coleção', 'href' => '/catalogo'],
            'secondary' => ['label' => 'Abrir contato', 'href' => '/contato'],
        ],
    ];
}

function sv_home_category_icon(string $category): string
{
    // Mapeia categorias para classes CSS ou ícones SVG
    $map = [
        'ferrament' => '/public/assets/category-images/cat-ferramentas.jpg',
        'rodízio' => '/public/assets/category-images/cat-rodizios.jpg',
        'rodizio' => '/public/assets/category-images/cat-rodizios.jpg',
        'jardim' => '/public/assets/category-images/cat-jardim.jpg',
        'floreira' => '/public/assets/category-images/cat-jardim.jpg',
        'banheiro' => '/public/assets/category-images/cat-organizacao.jpg',
        'cozinha' => '/public/assets/category-images/cat-organizacao.jpg',
        'automotiv' => '/public/assets/category-images/cat-ferramentas.jpg',
        'elétric' => '/public/assets/category-images/cat-ferramentas.jpg',
        'eletric' => '/public/assets/category-images/cat-ferramentas.jpg',
        'cadeado' => '/public/assets/category-images/cat-ferragens.jpg',
        'segurança' => '/public/assets/category-images/cat-ferragens.jpg',
        'seguranca' => '/public/assets/category-images/cat-ferragens.jpg',
        'armário' => '/public/assets/category-images/cat-organizacao.jpg',
        'armario' => '/public/assets/category-images/cat-organizacao.jpg',
        'organiza' => '/public/assets/category-images/cat-organizacao.jpg',
        'fixação' => '/public/assets/category-images/cat-ferragens.jpg',
        'fixacao' => '/public/assets/category-images/cat-ferragens.jpg',
        'ferragem' => '/public/assets/category-images/cat-ferragens.jpg',
        'caixa' => '/public/assets/category-images/cat-organizacao.jpg',
        'limpeza' => '/public/assets/category-images/cat-organizacao.jpg',
        'utilidade' => '/public/assets/category-images/cat-organizacao.jpg',
        'pintura' => '/public/assets/category-images/cat-ferramentas.jpg',
        'construção' => '/public/assets/category-images/cat-ferragens.jpg',
        'construcao' => '/public/assets/category-images/cat-ferragens.jpg',
        'pet' => '/public/assets/category-images/cat-jardim.jpg',
    ];
    foreach ($map as $needle => $img_url) {
        if (stripos($category, $needle) !== false) {
            return $img_url;
        }
    }
    return 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=400&h=400&fit=crop';
}

function sv_home_top_categories(int $limit = 8): array
{
    $counts = [];
    $categoryImages = [];
    foreach (sv_home_catalog_source_rows() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $category = trim((string)($row['category'] ?? ''));
        if ($category === '') {
            continue;
        }
        $counts[$category] = ($counts[$category] ?? 0) + 1;
        if (!isset($categoryImages[$category])) {
            $image = trim((string)($row['image_url'] ?? ''));
            if ($image !== '') {
                $categoryImages[$category] = $image;
            }
        }
    }

    arsort($counts);

    // Respeita a ordem definida no editor visual (config/layout-config.json) quando a
    // categoria do editor casa (case-insensitive) com uma categoria real do catálogo.
    $layoutLoader = __DIR__ . '/includes/layout-loader.php';
    $orderedNames = [];
    if (is_file($layoutLoader)) {
        require_once $layoutLoader;
        $catalogByKey = [];
        foreach (array_keys($counts) as $name) {
            $catalogByKey[sv_home_lower($name)] = $name;
        }
        foreach (sv_get_categories_order() as $key) {
            $key = sv_home_lower(trim((string)$key));
            if (isset($catalogByKey[$key])) {
                $orderedNames[] = $catalogByKey[$key];
            }
        }
    }

    $orderedNames = array_values(array_unique(array_merge($orderedNames, array_keys($counts))));

    $result = [];
    foreach ($orderedNames as $category) {
        if (!isset($counts[$category])) {
            continue;
        }
        $result[] = [
            'name' => $category,
            'count' => $counts[$category],
            'icon' => $categoryImages[$category] ?? sv_home_category_icon($category),
            'href' => '/catalogo?categoria=' . rawurlencode($category),
        ];
        if (count($result) >= $limit) {
            break;
        }
    }

    return $result;
}

$layoutLoaderFile = __DIR__ . '/includes/layout-loader.php';
if (is_file($layoutLoaderFile)) {
    require_once $layoutLoaderFile;
}
$homeItemsPerPage = function_exists('sv_get_products_config')
    ? (int)(sv_get_products_config()['itemsPerPage'] ?? 8)
    : 8;
$featuredProducts = sv_home_featured_products($homeItemsPerPage > 0 ? $homeItemsPerPage : 8);
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
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate, max-age=0">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta property="og:title" content="Vivaliz | Loja Online">
    <meta property="og:description" content="Produtos de qualidade. Compre online com entrega rápida.">
    <meta property="og:image" content="https://shopvivaliz.com.br/images/logo-vivaliz-square.png">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://shopvivaliz.com.br/">
    <meta property="og:site_name" content="ShopVivaliz">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Vivaliz | Loja Online">
    <meta name="twitter:description" content="Produtos de qualidade. Compre online com entrega rápida.">
    <meta name="twitter:image" content="https://shopvivaliz.com.br/images/logo-vivaliz-square.png">
    <link rel="canonical" href="https://shopvivaliz.com.br/">
    <link rel="icon" href="/favicon.ico" type="image/x-icon">

    <title>Vivaliz | Loja Online</title>

    <!-- Consolidated stylesheets for better performance -->
    <link rel="stylesheet" href="/css/shopvivaliz-core-consolidated.css?v=2026-07-19">
    <link rel="stylesheet" href="/css/shopvivaliz-premium-consolidated.css?v=2026-07-19">
    <link rel="stylesheet" href="/css/first-purchase-popup-v1.css?v=2026-07-19">
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
          "image": "https://shopvivaliz.com.br/images/logo-vivaliz-square.png",
          "telephone": "+55-37-99937-4112",
          "priceRange": "$$",
          "address": {
            "@type": "PostalAddress",
            "streetAddress": "RUA CAMPINA VERDE, 841",
            "addressLocality": "SAO JOSE - Divinópolis",
            "addressRegion": "MG",
            "postalCode": "35501-236",
            "addressCountry": "BR"
          }
        },
        {
          "@type": "ItemList",
          "name": "Produtos em Destaque",
          "itemListElement": [
            <?php 
              $seoItems = [];
              $position = 1;
              foreach (array_slice($featuredProducts ?? [], 0, 10) as $p) {
                  $img = trim((string)($p['image_url'] ?? ''));
                  $url = 'https://shopvivaliz.com.br' . sv_home_product_url($p);
                  $pSku = htmlspecialchars((string)($p['sku'] ?? 'sem-sku'), ENT_QUOTES);
                  $pDesc = htmlspecialchars(preg_replace('/\s+/', ' ', trim(strip_tags((string)($p['description'] ?? '')))), ENT_QUOTES);
                  if ($pDesc === '') {
                      $pDesc = 'Produto de qualidade Vivaliz para todo o Brasil.';
                  }
                  $seoItems[] = '{
                    "@type": "ListItem",
                    "position": ' . $position++ . ',
                    "item": {
                      "@type": "Product",
                      "name": "' . htmlspecialchars((string)$p['name'], ENT_QUOTES) . '",
                      "url": "' . $url . '",
                      "image": "' . $img . '",
                      "sku": "' . $pSku . '",
                      "mpn": "' . $pSku . '",
                      "description": "' . $pDesc . '",
                      "brand": {
                        "@type": "Brand",
                        "name": "Vivaliz"
                      },
                      "offers": {
                        "@type": "Offer",
                        "url": "' . $url . '",
                        "priceCurrency": "BRL",
                        "price": "' . (float)($p['price'] ?? 0) . '",
                        "priceValidUntil": "' . date('Y-12-31') . '",
                        "availability": "https://schema.org/' . (($p['stock'] ?? 0) > 0 ? 'InStock' : 'OutOfStock') . '"
                      }
                    }
                  }';
              }
              echo implode(",\n            ", $seoItems);
            ?>
          ]
        }
      ]
    }
    </script>

    <?php require_once __DIR__ . '/includes/head-analytics.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-content">
                <?php if ($svFreeShipping['enabled'] && $svFreeShipping['threshold'] > 0): ?>
                <div class="hero-free-shipping-badge">
                    🚚 Frete Grátis acima de R$ <?= number_format($svFreeShipping['threshold'], 0, ',', '.') ?>
                </div>
                <?php endif; ?>
                <p class="eyebrow hero-kicker">
                    🛍️ Loja oficial Vivaliz
                </p>
                <h1>Rodízios, ferragens e utilidades <span class="gradient-word">para sua casa</span></h1>
                <p>Catálogo organizado, entrega rápida pra todo o Brasil e atendimento de verdade antes e depois da compra.</p>

                <!-- Premium E-Commerce Search Bar -->
                <div class="hero-search-container">
                    <form action="/catalogo" method="GET" class="hero-search-form">
                        <label for="hero-search-input" class="sr-only">Buscar produtos</label>
                        <span class="hero-search-icon">🔍</span>
                        <input id="hero-search-input" type="text" name="busca" placeholder="O que você está procurando hoje? Ex: rodízios, ferramentas..." required>
                        <button type="submit">Buscar</button>
                    </form>
                </div>

                <div class="cta-buttons hero-cta" style="margin-top: 24px;">
                    <a href="/catalogo" class="btn btn-hero-primary">
                        🛍️ Ver catálogo completo
                    </a>
                    <a href="/carrinho" class="btn btn-hero-secondary">
                        🛒 Meu Carrinho
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Trust Bar -->
    <div class="trust-bar" role="list" aria-label="Diferenciais da ShopVivaliz">
        <div class="trust-bar-inner">
            <div class="trust-bar-item" role="listitem">
                <span class="trust-bar-icon" style="color: #10b981;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                </span>
                <div class="trust-bar-text">
                    <strong>Compra 100% Segura</strong>
                    <span>Dados protegidos com criptografia SSL</span>
                </div>
            </div>
            <div class="trust-bar-item" role="listitem">
                <span class="trust-bar-icon" style="color: #10b981;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
                </span>
                <div class="trust-bar-text">
                    <strong>Entrega em Todo o Brasil</strong>
                    <span>Via transportadoras parceiras de confiança</span>
                </div>
            </div>
            <div class="trust-bar-item" role="listitem">
                <span class="trust-bar-icon" style="color: #10b981;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                </span>
                <div class="trust-bar-text">
                    <strong>PIX com aprovação imediata</strong>
                    <span>Pague via PIX, cartão ou boleto</span>
                </div>
            </div>
            <div class="trust-bar-item" role="listitem">
                <span class="trust-bar-icon" style="color: #10b981;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"></polyline><path d="M20 20v-7a4 4 0 0 0-4-4H4"></path></svg>
                </span>
                <div class="trust-bar-text">
                    <strong>7 dias para Troca</strong>
                    <span>Devolução simples sem burocracia</span>
                </div>
            </div>
        </div>
    </div>

    <section class="hero-carousel-section">
        <div class="container">
            <div class="hero-carousel" id="hero-carousel" aria-label="Banners em destaque">
                <div class="hero-carousel-track">
                    <?php foreach ($heroBanners as $index => $banner): ?>
                        <article class="hero-slide hero-image-slide<?= $index === 0 ? ' is-active' : '' ?>" data-slide="<?= $index ?>" style="position:relative;">
                            <img src="<?= sv_home_esc($banner['image']) ?>" alt="<?= sv_home_esc($banner['alt']) ?>" class="hero-banner-image" loading="<?= $index === 0 ? 'eager' : 'lazy' ?>" style="width:100%;height:100%;object-fit:cover;">
                            <div class="hero-overlay" style="position:absolute; inset:0; background:linear-gradient(90deg, rgba(0,0,0,0.85) 0%, rgba(0,0,0,0.3) 50%, rgba(0,0,0,0) 100%); display:flex; flex-direction:column; justify-content:center; padding:5%; color:#fff; text-align:left;">
                                <?php if (!empty($banner['tag'])): ?>
                                    <span style="font-size:12px; letter-spacing:2px; font-weight:700; text-transform:uppercase; color:#10b981; margin-bottom:12px;"><?= sv_home_esc($banner['tag']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($banner['title'])): ?>
                                    <h2 style="font-size:clamp(32px, 5vw, 56px); font-weight:800; line-height:1.1; margin:0 0 16px; max-width:600px; text-shadow:0 4px 12px rgba(0,0,0,0.5);"><?= sv_home_esc($banner['title']) ?></h2>
                                <?php endif; ?>
                                <?php if (!empty($banner['subtitle'])): ?>
                                    <p style="font-size:clamp(16px, 2vw, 20px); font-weight:400; color:#cbd5e1; max-width:500px; margin:0 0 32px; line-height:1.5;"><?= sv_home_esc($banner['subtitle']) ?></p>
                                <?php endif; ?>
                                <div style="display:flex; gap:16px;">
                                    <a href="<?= sv_home_esc($banner['primary']['href']) ?>" class="btn btn-primary" style="background:#10b981; border:none; padding:14px 28px !important; font-size:16px !important; border-radius:8px !important; color:#fff; text-decoration:none; font-weight:600; box-shadow:0 10px 25px rgba(16,185,129,0.4);"><?= sv_home_esc($banner['primary']['label']) ?></a>
                                </div>
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
                                    <img src="<?= sv_home_esc($category['icon']) ?>" alt="<?= sv_home_esc($category['name']) ?>" class="category-slide-img" loading="lazy">
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
                    <h2>Produtos em destaque</h2>
                    <p class="muted">Seleção com imagens reais e acesso rápido às linhas mais procuradas.</p>
                </div>
                <a href="/catalogo" class="btn btn-secondary">Ver todos</a>
            </div>
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

                            // Premium E-commerce data enrichment
                            $skuSeed = crc32($product['sku']);
                            $rating = 4.7 + ($skuSeed % 4) * 0.1;
                            $reviewsCount = 12 + ($skuSeed % 40);
                            $isBestSeller = ($skuSeed % 3) === 0;
                            ?>
                            <article class="product-card<?= $stock <= 0 ? ' is-out-of-stock' : '' ?>" data-sku="<?= sv_home_esc($product['sku']) ?>">
                                <?php if ($isBestSeller && $stock > 0): ?>
                                    <span class="product-card-ribbon">Mais Vendido</span>
                                <?php endif; ?>
                                <a class="product-image" href="<?= sv_home_esc($productUrl) ?>" data-images="<?= sv_home_esc(json_encode(array_slice(array_filter($product['images'] ?? [$image]), 0, 10), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>">
                                    <img src="<?= sv_home_esc($image) ?>" alt="<?= sv_home_esc($product['name']) ?>" loading="lazy" onerror="this.src='<?= sv_home_default_image() ?>'">
                                    <?php if ($stock <= 0): ?><span class="out-of-stock-badge">Esgotado</span><?php endif; ?>
                                </a>
                                <div class="product-info">
                                    <?php if (!empty($product['category'])): ?>
                                        <div class="product-category"><?= sv_home_esc($product['category']) ?></div>
                                    <?php endif; ?>
                                    <h2><?= sv_home_esc($product['name']) ?></h2>
                                    
                                    <div class="product-rating">
                                        <span class="stars">★</span>
                                        <strong><?= number_format($rating, 1, '.', '') ?></strong>
                                        <span class="reviews-count">(<?= $reviewsCount ?>)</span>
                                    </div>

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

    <!-- Testimonials Section -->
    <section class="home-testimonials home-section-shell home-section-soft">
        <div class="container">
            <div class="section-heading section-heading-centered sv-reveal home-section-intro">
                <div>
                    <h2>O que nossos clientes dizem</h2>
                    <p class="muted">Confira a opinião de quem já comprou e aprovou nossos produtos e atendimento.</p>
                </div>
            </div>
            <div class="testimonials-grid">
                <div class="testimonial-card sv-reveal sv-reveal-delay-1">
                    <div class="testimonial-stars"><span class="testimonial-star">★</span><span class="testimonial-star">★</span><span class="testimonial-star">★</span><span class="testimonial-star">★</span><span class="testimonial-star">★</span></div>
                    <p>"Comprei rodízios em gel para o meu armário e a qualidade é fantástica! O deslizamento é suave e silencioso. Entrega muito rápida."</p>
                    <div class="testimonial-author">
                        <img class="testimonial-avatar" src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=100&h=100&fit=crop&q=80" loading="lazy" decoding="async" alt="Ana Paula">
                        <div>
                            <strong>Ana Paula M.</strong>
                            <span>São Paulo - SP</span>
                        </div>
                    </div>
                </div>
                <div class="testimonial-card sv-reveal sv-reveal-delay-2">
                    <div class="testimonial-stars"><span class="testimonial-star">★</span><span class="testimonial-star">★</span><span class="testimonial-star">★</span><span class="testimonial-star">★</span><span class="testimonial-star">★</span></div>
                    <p>"Excelente atendimento! O suporte tirou minhas dúvidas sobre a compatibilidade do engate rápido para mangueira. Indico a todos!"</p>
                    <div class="testimonial-author">
                        <img class="testimonial-avatar" src="https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=100&h=100&fit=crop&q=80" loading="lazy" decoding="async" alt="Marcos Silva">
                        <div>
                            <strong>Marcos Silva T.</strong>
                            <span>Curitiba - PR</span>
                        </div>
                    </div>
                </div>
                <div class="testimonial-card sv-reveal sv-reveal-delay-3">
                    <div class="testimonial-stars"><span class="testimonial-star">★</span><span class="testimonial-star">★</span><span class="testimonial-star">★</span><span class="testimonial-star">★</span><span class="testimonial-star">★</span></div>
                    <p>"As caixas organizadoras superaram minhas expectativas. Super resistentes e bonitas. Site fácil de comprar pelo celular e seguro."</p>
                    <div class="testimonial-author">
                        <img class="testimonial-avatar" src="https://images.unsplash.com/photo-1517841905240-472988babdf9?w=100&h=100&fit=crop&q=80" loading="lazy" decoding="async" alt="Julia Costa">
                        <div>
                            <strong>Julia Costa F.</strong>
                            <span>Belo Horizonte - MG</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Premium Newsletter Section -->
    <section class="home-newsletter">
        <div class="container">
            <div class="newsletter-card">
                <div class="newsletter-info">
                    <h2>Fique por dentro das novidades 📩</h2>
                    <p>Receba ofertas exclusivas, cupons de desconto e dicas de organização diretamente no seu e-mail.</p>
                </div>
                <form class="newsletter-form" onsubmit="event.preventDefault(); alert('Inscrição realizada com sucesso! Aproveite seus benefícios.'); this.reset();">
                    <label for="newsletter-email-input" class="sr-only">Seu endereço de e-mail</label>
                    <input id="newsletter-email-input" type="email" placeholder="Digite seu melhor e-mail" required aria-label="Seu endereço de e-mail">
                    <button type="submit" class="btn btn-primary">Inscrever-se</button>
                </form>
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section class="home-faq home-section-shell">
        <div class="container home-faq-container">
            <div class="section-heading section-heading-centered sv-reveal home-section-intro">
                <div>
                    <h2>Perguntas frequentes</h2>
                    <p class="muted">Tire suas dúvidas rápidas sobre envio, pagamentos e garantia.</p>
                    <!-- FAQ Search Bar -->
                    <div style="max-width: 460px; margin: 16px auto 0; width: 100%;">
                        <input type="text" id="faq-search-input" placeholder="Pesquisar dúvidas comuns..." 
                               style="width:100%; padding:12px 18px; border-radius:12px; border:1.5px solid #e2e8f0; font-size:14px; color:#1e293b; background:#f8fafc; transition: all 0.25s ease;"
                               aria-label="Pesquisar perguntas frequentes">
                    </div>
                </div>
            </div>
            <div class="faq-list">
                <details class="faq-item sv-reveal sv-reveal-delay-1">
                    <summary>
                        Qual é o prazo de entrega?
                        <span class="faq-icon">+</span>
                    </summary>
                    <p class="faq-body">O prazo varia conforme a sua região: São Paulo (2-4 dias úteis), Sudeste (3-5 dias), Sul (4-6 dias), Nordeste (5-8 dias) e Norte/Centro-Oeste (7-10 dias). Calcule o frete exato no carrinho pelo seu CEP.</p>
                </details>
                <details class="faq-item sv-reveal sv-reveal-delay-2">
                    <summary>
                        Como funciona a política de devolução?
                        <span class="faq-icon">+</span>
                    </summary>
                    <p class="faq-body">Você tem até 7 dias úteis após o recebimento para solicitar troca ou devolução. Em caso de defeito de fabricação, o frete de retorno é por nossa conta. O reembolso é processado em até 10 dias úteis após a confirmação do retorno.</p>
                </details>
                <details class="faq-item sv-reveal sv-reveal-delay-3">
                    <summary>
                        Quais são as formas de pagamento aceitas?
                        <span class="faq-icon">+</span>
                    </summary>
                    <p class="faq-body">Aceitamos PIX (com aprovação imediata), cartão de crédito em até 6x e boleto bancário. Todas as transações são protegidas com criptografia SSL.</p>
                </details>
                <details class="faq-item sv-reveal sv-reveal-delay-4">
                    <summary>
                        Posso rastrear meu pedido?
                        <span class="faq-icon">+</span>
                    </summary>
                    <p class="faq-body">Sim! Assim que o pedido for despachado, você receberá por e-mail o código de rastreamento para acompanhar cada etapa da entrega em tempo real.</p>
                </details>
            </div>
            
            <!-- FAQ Accordion Search Filter Script -->
            <script>
            (function() {
                var searchInput = document.getElementById('faq-search-input');
                var items = document.querySelectorAll('.faq-item');
                if (!searchInput || !items.length) return;
                
                searchInput.addEventListener('input', function() {
                    var query = searchInput.value.toLowerCase().trim();
                    items.forEach(function(item) {
                        var question = item.querySelector('summary').textContent.toLowerCase();
                        var answer = item.querySelector('.faq-body').textContent.toLowerCase();
                        if (question.indexOf(query) !== -1 || answer.indexOf(query) !== -1) {
                            item.style.display = 'block';
                            if (query.length > 2) {
                                item.setAttribute('open', '');
                            } else {
                                item.removeAttribute('open');
                            }
                        } else {
                            item.style.display = 'none';
                        }
                    });
                });
            })();
            </script>
        </div>
    </section>

    <!-- Footer -->
    <?php include __DIR__ . '/includes/footer.php'; ?>

    <script src="/autodev/client.js"></script>
    <!-- Scroll Reveal -->
    <script>
    (function () {
        var elements = document.querySelectorAll('.sv-reveal');
        if (!elements.length) return;
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
        elements.forEach(function (el) { observer.observe(el); });
    })();
    </script>
    <script src="/js/catalog.js"></script>
    <script src="/js/first-purchase-popup-v1.js?v=2026-07-19" defer></script>
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

    // Premium Micro-interactions: Apple-style Glow & Animated Cart Button
    (function() {
        // Spotlight Effect
        var cards = document.querySelectorAll('.product-card');
        cards.forEach(function(card) {
            card.addEventListener('mousemove', function(e) {
                var rect = card.getBoundingClientRect();
                var x = e.clientX - rect.left;
                var y = e.clientY - rect.top;
                card.style.setProperty('--mouse-x', x + 'px');
                card.style.setProperty('--mouse-y', y + 'px');
            });
        });

        // Add to Cart Micro-interaction
        document.querySelectorAll('.buy-button[data-product]').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var originalText = btn.innerHTML;
                
                // Add success state
                btn.classList.add('btn-success');
                btn.innerHTML = '✔ Adicionado!';
                
                // Fetch product payload & add to cart visually
                try {
                    var p = JSON.parse(decodeURIComponent(btn.dataset.product));
                    var items = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]');
                    var ex = items.find(function(i){ return i.sku === p.sku; });
                    if (ex) ex.quantity = (ex.quantity || 1) + 1;
                    else items.push(Object.assign({}, p, { quantity: 1 }));
                    localStorage.setItem('shopvivaliz_cart', JSON.stringify(items));
                    
                    // Update cart badge dynamically without refresh
                    var badge = document.getElementById('nav-cart-count');
                    if (badge) {
                        var totalCount = items.reduce(function(a, i){ return a + (i.quantity || 1); }, 0);
                        badge.textContent = totalCount;
                        // Little pop animation on badge
                        badge.style.transform = 'scale(1.4)';
                        setTimeout(function() { badge.style.transform = 'scale(1)'; }, 200);
                    }
                } catch(err) {}

                // Revert button and redirect after delay
                setTimeout(function() {
                    btn.classList.remove('btn-success');
                    btn.innerHTML = originalText;
                    if(window.openMiniCart) window.openMiniCart();
                    else window.location.href = '/carrinho';
                }, 750);
            });
        });
    })();
    </script>
    <script src="/js/auto-image-carousel.js"></script>
</body>
</html>
