<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');

$sku_raw = strip_tags((string)($_GET['sku'] ?? $_GET['id'] ?? ''));
$sku = htmlspecialchars($sku_raw);

// Carrega dados do produto server-side para SEO
$seo_nome      = 'Produto — ShopVivaliz';
$seo_desc      = 'Compre produtos de qualidade com entrega rápida e segura na ShopVivaliz.';
$seo_img       = '';
$seo_preco     = '';
$seo_categoria = '';
$seo_produto   = null;

if ($sku_raw !== '') {
    $arr_file = __DIR__ . '/../olist/produtos-olist-array.php';
    if (is_readable($arr_file)) {
        include $arr_file;
        $todos = $GLOBALS['produtos_olist'] ?? [];
        foreach ($todos as $p) {
            if (isset($p['id']) && strcasecmp((string)$p['id'], $sku_raw) === 0) {
                $seo_produto   = $p;
                $seo_nome      = trim($p['nome'] ?? '') ?: $seo_nome;
                $seo_desc      = trim($p['descricao'] ?? '') !== ''
                    ? substr(trim($p['descricao']), 0, 160) . ' — ShopVivaliz'
                    : $seo_nome . ' — Compre na ShopVivaliz com entrega rápida.';
                $seo_img       = trim($p['url_imagem'] ?? '');
                $seo_preco     = isset($p['preco']) && $p['preco'] > 0
                    ? number_format((float)$p['preco'], 2, '.', '') : '';
                $seo_categoria = trim($p['categoria'] ?? '');
                break;
            }
        }
    }
}

$seo_title = $seo_nome . ($seo_nome !== 'Produto — ShopVivaliz' ? ' — ShopVivaliz' : '');
$base_url  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
           . '://' . ($_SERVER['HTTP_HOST'] ?? 'shopvivaliz.com.br');
$canonical = $base_url . '/claude/produto.php?sku=' . rawurlencode($sku_raw);
$og_img    = $seo_img !== '' ? ($seo_img[0] === '/' ? $base_url . $seo_img : $seo_img) : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars($seo_desc) ?>">
    <title><?= htmlspecialchars($seo_title) ?></title>
    <link rel="canonical" href="<?= htmlspecialchars($canonical) ?>">
    <!-- Open Graph -->
    <meta property="og:type"        content="product">
    <meta property="og:title"       content="<?= htmlspecialchars($seo_nome) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($seo_desc) ?>">
    <meta property="og:url"         content="<?= htmlspecialchars($canonical) ?>">
    <meta property="og:site_name"   content="ShopVivaliz">
    <?php if ($og_img !== ''): ?>
    <meta property="og:image"       content="<?= htmlspecialchars($og_img) ?>">
    <?php endif; ?>
    <?php if ($seo_preco !== ''): ?>
    <meta property="product:price:amount"   content="<?= htmlspecialchars($seo_preco) ?>">
    <meta property="product:price:currency" content="BRL">
    <?php endif; ?>
    <!-- Twitter Card -->
    <meta name="twitter:card"        content="summary_large_image">
    <meta name="twitter:title"       content="<?= htmlspecialchars($seo_nome) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($seo_desc) ?>">
    <?php if ($og_img !== ''): ?>
    <meta name="twitter:image"       content="<?= htmlspecialchars($og_img) ?>">
    <?php endif; ?>
    <?php if ($seo_produto !== null): ?>
    <script type="application/ld+json">
    <?= json_encode([
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        'name'        => $seo_nome,
        'description' => $seo_desc,
        'sku'         => $sku_raw,
        'category'    => $seo_categoria,
        'image'       => $og_img !== '' ? [$og_img] : [],
        'url'         => $canonical,
        'brand'       => ['@type' => 'Brand', 'name' => 'ShopVivaliz'],
        'offers'      => $seo_preco !== '' ? [
            '@type'         => 'Offer',
            'priceCurrency' => 'BRL',
            'price'         => $seo_preco,
            'availability'  => 'https://schema.org/InStock',
            'url'           => $canonical,
        ] : null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
    </script>
    <?php endif; ?>
    <link rel="stylesheet" href="/css/responsive.css">
    <style>
        :root { --green:#22c55e; --navy:#1e3a5f; }

        .product-detail-page { padding-bottom: 60px; }

        .product-detail-hero {
            background: linear-gradient(135deg, var(--navy), #2563eb);
            color: white; padding: 32px 0;
        }
        .breadcrumb { font-size: 13px; opacity: .75; margin-bottom: 6px; }
        .breadcrumb a { color: white; text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }

        .product-layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 32px;
            padding: 40px 0;
        }
        @media (min-width: 768px) {
            .product-layout { grid-template-columns: 1fr 1fr; }
        }

        .product-image-wrap {
            background: #f3f4f6; border-radius: 12px; overflow: hidden;
            display: flex; align-items: center; justify-content: center;
            min-height: 320px;
        }
        .product-image-wrap img {
            width: 100%; max-height: 420px; object-fit: contain;
        }

        .product-info-panel { display: flex; flex-direction: column; gap: 16px; }

        .product-sku-badge {
            font-size: 11px; text-transform: uppercase; letter-spacing: .08em;
            color: #6b7280; font-weight: 600;
        }
        .product-title {
            font-size: 1.6rem; font-weight: 700; color: #1f2937; line-height: 1.3;
        }
        .product-price {
            font-size: 2rem; font-weight: 800; color: var(--navy);
        }
        .product-meta-row {
            display: flex; gap: 12px; flex-wrap: wrap; font-size: 13px; color: #6b7280;
        }
        .product-meta-row span {
            background: #f3f4f6; border-radius: 20px; padding: 4px 12px;
        }

        .qty-row { display: flex; align-items: center; gap: 12px; }
        .qty-btn {
            width: 36px; height: 36px; border: 1px solid #e5e7eb;
            background: white; border-radius: 6px; font-size: 18px;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
        }
        .qty-input {
            width: 56px; text-align: center; border: 1px solid #e5e7eb;
            border-radius: 6px; padding: 8px; font-size: 15px; font-weight: 600;
        }

        .btn-buy {
            padding: 16px 0; background: var(--green); color: white;
            border: none; border-radius: 8px; font-size: 16px; font-weight: 700;
            cursor: pointer; width: 100%; transition: background .2s;
        }
        .btn-buy:hover { background: #16a34a; }

        .btn-cart {
            padding: 14px 0; background: white; color: var(--navy);
            border: 2px solid var(--navy); border-radius: 8px;
            font-size: 15px; font-weight: 700; cursor: pointer; width: 100%;
            transition: all .2s; margin-top: 8px;
        }
        .btn-cart:hover { background: var(--navy); color: white; }

        .toast {
            position: fixed; bottom: 24px; right: 24px;
            background: #1f2937; color: white; padding: 14px 20px;
            border-radius: 10px; font-size: 14px; font-weight: 600;
            opacity: 0; transform: translateY(12px);
            transition: all .3s; z-index: 999; pointer-events: none;
        }
        .toast.show { opacity: 1; transform: translateY(0); }

        .state-loading, .state-error {
            text-align: center; padding: 80px 20px; color: #6b7280;
        }
        .state-error { color: #ef4444; }

        .menu-toggle {
            display: none; background: none; border: none; font-size: 22px;
            cursor: pointer; padding: 4px 8px; color: #1f2937;
        }
        @media (max-width: 767px) {
            .menu-toggle { display: block; }
            .navbar-menu {
                display: none; flex-direction: column; position: absolute;
                top: 60px; left: 0; right: 0; background: white;
                border-bottom: 1px solid #e5e7eb; padding: 12px 16px; z-index: 200;
            }
            .navbar-menu.active { display: flex; }
        }
    </style>
</head>
<body>

<nav class="navbar">
    <div class="container">
        <div class="navbar-brand">
            <a href="/" style="display:flex;align-items:center;text-decoration:none;">
                <img src="/images/logo.svg" alt="ShopVivaliz" style="height:44px;width:auto;"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                <span style="display:none;font-size:20px;font-weight:700;color:#1e3a5f;">ShopVivaliz</span>
            </a>
        </div>
        <button class="menu-toggle" id="menuToggle" aria-label="Menu">&#9776;</button>
        <div class="navbar-menu" id="navMenu">
            <a href="/">Home</a>
            <a href="catalogo">Cat&#225;logo</a>
            <a href="/sobre">Sobre</a>
            <a href="/contato">Contato</a>
            <a href="carrinho">&#128722; Carrinho</a>
        </div>
    </div>
</nav>

<main class="product-detail-page">
    <section class="product-detail-hero">
        <div class="container">
            <p class="breadcrumb">
                <a href="/">Home</a> &rsaquo;
                <a href="catalogo">Cat&#225;logo</a> &rsaquo;
                <span id="bc-name">Produto</span>
            </p>
        </div>
    </section>
    <div class="container" id="product-container">
        <div class="state-loading">Carregando produto&hellip;</div>
    </div>
</main>

<footer>
    <div class="container">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:24px;margin-bottom:24px;text-align:left;">
            <div>
                <h3 style="margin-bottom:10px;font-size:14px;">Compras</h3>
                <ul style="list-style:none;padding:0;display:flex;flex-direction:column;gap:7px;">
                    <li><a href="/" style="color:#ccc;text-decoration:none;font-size:13px;">Home</a></li>
                    <li><a href="catalogo" style="color:#ccc;text-decoration:none;font-size:13px;">Cat&#225;logo</a></li>
                    <li><a href="carrinho" style="color:#ccc;text-decoration:none;font-size:13px;">Carrinho</a></li>
                </ul>
            </div>
            <div>
                <h3 style="margin-bottom:10px;font-size:14px;">Suporte</h3>
                <ul style="list-style:none;padding:0;display:flex;flex-direction:column;gap:7px;">
                    <li><a href="/contato" style="color:#ccc;text-decoration:none;font-size:13px;">Contato</a></li>
                    <li><a href="/faq" style="color:#ccc;text-decoration:none;font-size:13px;">FAQ</a></li>
                    <li><a href="/politica-privacidade" style="color:#ccc;text-decoration:none;font-size:13px;">Privacidade</a></li>
                </ul>
            </div>
        </div>
        <div style="border-top:1px solid #444;padding-top:16px;text-align:center;">
            <p style="font-size:13px;">&copy; 2026 ShopVivaliz. Todos os direitos reservados.</p>
        </div>
    </div>
</footer>

<div class="toast" id="toast"></div>

<script>
(function () {
    var initialSku = <?= json_encode($sku) ?>;

    function esc(v) {
        return String(v || '').replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[c];
        });
    }

    function money(v) {
        var n = Number(v || 0);
        if (!n) return 'Preço sob consulta';
        return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function showToast(msg) {
        var t = document.getElementById('toast');
        t.textContent = msg;
        t.classList.add('show');
        setTimeout(function () { t.classList.remove('show'); }, 3000);
    }

    function addToCart(product, qty) {
        var items = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]');
        var existing = items.find(function (i) { return i.sku === product.sku; });
        if (existing) {
            existing.quantity += qty;
        } else {
            items.push({
                sku: product.sku,
                name: product.name,
                image_url: product.image_url || '/favicon.ico',
                price: Number(product.price || 0),
                olist_product_id: product.olist_product_id || '',
                quantity: qty
            });
        }
        localStorage.setItem('shopvivaliz_cart', JSON.stringify(items));
    }

    function renderProduct(product) {
        var name  = product.name || product.sku || 'Produto';
        var price = money(product.price);
        var image = product.image_url || '/favicon.ico';
        var sku   = product.sku || product.olist_product_id || '';
        var imgs  = Number(product.images_count || 0);

        document.title = name + ' — ShopVivaliz';
        var bc = document.getElementById('bc-name');
        if (bc) bc.textContent = name;

        var container = document.getElementById('product-container');
        container.innerHTML = [
            '<div class="product-layout">',
              '<div class="product-image-wrap">',
                '<img src="' + esc(image) + '" alt="' + esc(name) + '" onerror="this.src=\'/favicon.ico\'">',
              '</div>',
              '<div class="product-info-panel">',
                '<div class="product-sku-badge">SKU: ' + esc(sku) + '</div>',
                '<h1 class="product-title">' + esc(name) + '</h1>',
                '<div class="product-price">' + esc(price) + '</div>',
                '<div class="product-meta-row">',
                  '<span>' + imgs + ' imagem' + (imgs === 1 ? '' : 's') + '</span>',
                '</div>',
                '<div class="qty-row">',
                  '<button class="qty-btn" id="qty-minus" aria-label="Diminuir">&#8722;</button>',
                  '<input class="qty-input" id="qty-input" type="number" value="1" min="1" max="99" aria-label="Quantidade">',
                  '<button class="qty-btn" id="qty-plus" aria-label="Aumentar">+</button>',
                '</div>',
                '<button class="btn-buy" id="btn-buy" type="button">Comprar agora</button>',
                '<button class="btn-cart" id="btn-cart" type="button">&#10010; Adicionar ao carrinho</button>',
              '</div>',
            '</div>'
        ].join('');

        var qtyInput = document.getElementById('qty-input');
        document.getElementById('qty-minus').addEventListener('click', function () {
            var v = parseInt(qtyInput.value, 10);
            if (v > 1) qtyInput.value = v - 1;
        });
        document.getElementById('qty-plus').addEventListener('click', function () {
            qtyInput.value = parseInt(qtyInput.value, 10) + 1;
        });
        document.getElementById('btn-buy').addEventListener('click', function () {
            addToCart(product, Math.max(1, parseInt(qtyInput.value, 10) || 1));
            window.location.href = 'carrinho';
        });
        document.getElementById('btn-cart').addEventListener('click', function () {
            addToCart(product, Math.max(1, parseInt(qtyInput.value, 10) || 1));
            showToast('✅ Adicionado ao carrinho!');
        });
    }

    function renderError(msg) {
        document.getElementById('product-container').innerHTML =
            '<div class="state-error">' + esc(msg) +
            '<br><br><a href="catalogo" style="color:#2563eb;">← Voltar ao catálogo</a></div>';
    }

    function loadProduct(sku) {
        fetch('api/catalog/products.php?sku=' + encodeURIComponent(sku) + '&limit=1', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var products = Array.isArray(data.products) ? data.products : [];
                var product = products.find(function (p) {
                    return (p.sku || p.olist_product_id) === sku;
                }) || products[0];
                if (!product) { renderError('Produto não encontrado.'); return; }
                renderProduct(product);
            })
            .catch(function () { renderError('Não foi possível carregar o produto agora.'); });
    }

    var toggle = document.getElementById('menuToggle');
    var navMenu = document.getElementById('navMenu');
    if (toggle && navMenu) {
        toggle.addEventListener('click', function () { navMenu.classList.toggle('active'); });
        navMenu.querySelectorAll('a').forEach(function (a) {
            a.addEventListener('click', function () { navMenu.classList.remove('active'); });
        });
    }

    if (initialSku) {
        loadProduct(initialSku);
    } else {
        renderError('Nenhum SKU informado. Acesse pelo catálogo.');
    }
})();
</script>
</body>
</html>
