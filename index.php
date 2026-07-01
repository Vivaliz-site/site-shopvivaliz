<?php
/**
 * ShopVivaliz - Homepage
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: text/html; charset=UTF-8');

define('APP_VERSION', '9.2.85');
define('APP_NAME', 'ShopVivaliz');

// ── Conexão com banco de dados ────────────────────────────────────────────────
function home_load_env(): void
{
    $path = __DIR__ . '/.env';
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim(trim($v), "\"'");
        if ($k !== '' && getenv($k) === false) putenv("$k=$v");
    }
}

function home_db(): ?mysqli
{
    if (!class_exists('mysqli')) return null;
    home_load_env();
    $constants = __DIR__ . '/config/constants.php';
    if (is_file($constants)) @include_once $constants;
    $host = defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: 'localhost');
    $port = (int)(defined('DB_PORT') ? DB_PORT : (getenv('DB_PORT') ?: 3306));
    $name = defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: '');
    $user = defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: '');
    $pass = defined('DB_PASS') ? DB_PASS : (getenv('DB_PASS') ?: '');
    if ($name === '' || $user === '') return null;
    mysqli_report(MYSQLI_REPORT_OFF);
    $db = @new mysqli((string)$host, (string)$user, (string)$pass, (string)$name, $port);
    if ($db->connect_errno) return null;
    $db->set_charset('utf8mb4');
    return $db;
}

function home_table_exists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}

function home_load_products(int $limit = 8): array
{
    try {
        $db = home_db();
        if (!$db) return [];
        if (home_table_exists($db, 'olist_products')) {
            $stmt = $db->prepare(
                'SELECT id, sku, name, primary_image_url AS image_url
                 FROM olist_products
                 WHERE primary_image_url IS NOT NULL AND primary_image_url != ""
                 ORDER BY updated_at DESC, id DESC LIMIT ?'
            );
            if ($stmt) {
                $stmt->bind_param('i', $limit); $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                if ($rows) return $rows;
            }
        }
        if (home_table_exists($db, 'products')) {
            $stmt = $db->prepare(
                'SELECT id, sku, name, price, image_url
                 FROM products
                 WHERE active=1 AND image_url IS NOT NULL AND image_url != ""
                 ORDER BY updated_at DESC, id DESC LIMIT ?'
            );
            if ($stmt) {
                $stmt->bind_param('i', $limit); $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                if ($rows) return $rows;
            }
        }
    } catch (Throwable) {}
    return [];
}

$featured_products = home_load_products(8);
$has_products = count($featured_products) > 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="ShopVivaliz - Sua loja online de qualidade. Produtos variados com entrega rápida e segura.">
    <meta name="theme-color" content="#1e3a5f">
    <title><?= APP_NAME ?> - Sua Loja Online de Confiança</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --brand-green:#22c55e; --brand-navy:#1e3a5f; --brand-dark:#1a3050; }

        /* ===== BANNER SLIDER ===== */
        .banner-slider { position:relative; width:100%; height:300px; overflow:hidden; margin-bottom:40px; }
        /* FIX: display:none aqui; .active define display:flex */
        .banner-slide {
            display:none; width:100%; height:100%;
            position:absolute; top:0; left:0;
            color:white; align-items:center; justify-content:center;
            text-align:center; padding:20px;
        }
        .banner-slide.active { display:flex; }
        .banner-slide:nth-child(1) { background:linear-gradient(135deg,var(--brand-navy),#2563eb); }
        .banner-slide:nth-child(2) { background:linear-gradient(135deg,#065f46,var(--brand-green)); }
        .banner-slide:nth-child(3) { background:linear-gradient(135deg,#7c3aed,#4f46e5); }
        .banner-slide h2 { font-size:28px; margin-bottom:10px; }
        .banner-slide p  { font-size:16px; margin-bottom:20px; opacity:.95; }
        .banner-btn {
            display:inline-block; padding:12px 28px; background:white;
            color:var(--brand-navy); font-weight:700; border-radius:6px;
            text-decoration:none; transition:transform .2s,box-shadow .2s;
        }
        .banner-btn:hover { transform:translateY(-2px); box-shadow:0 4px 14px rgba(0,0,0,.25); }
        .banner-arrow {
            position:absolute; top:50%; transform:translateY(-50%);
            background:rgba(255,255,255,.22); border:none; color:white;
            font-size:24px; width:42px; height:42px; border-radius:50%;
            cursor:pointer; z-index:10; transition:background .2s;
        }
        .banner-arrow:hover { background:rgba(255,255,255,.42); }
        .banner-arrow.prev { left:14px; } .banner-arrow.next { right:14px; }
        .banner-dots {
            position:absolute; bottom:14px; left:50%; transform:translateX(-50%);
            display:flex; gap:8px; z-index:10;
        }
        .dot { width:10px; height:10px; border-radius:50%; background:rgba(255,255,255,.4); cursor:pointer; border:none; transition:all .3s; }
        .dot.active { background:white; width:30px; border-radius:5px; }

        /* ===== BUSCA ===== */
        .search-bar { display:flex; gap:8px; margin:20px 0 32px; }
        .search-bar input { flex:1; padding:13px 16px; border:2px solid #e5e7eb; border-radius:6px; font-size:15px; transition:border-color .2s; }
        .search-bar input:focus { outline:none; border-color:var(--brand-navy); }
        .search-bar button { padding:13px 24px; background:var(--brand-navy); color:white; border:none; border-radius:6px; font-weight:600; cursor:pointer; transition:background .2s; }
        .search-bar button:hover { background:var(--brand-dark); }

        /* ===== CATEGORIAS ===== */
        .categories-section { padding:40px 0; background:#f9fafb; }
        .categories-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; }
        .category-card {
            background:white; padding:20px 12px; border-radius:10px; text-align:center;
            text-decoration:none; color:inherit; border:2px solid transparent;
            box-shadow:0 1px 3px rgba(0,0,0,.06); transition:all .2s; display:block;
        }
        .category-card:hover { border-color:var(--brand-green); transform:translateY(-4px); box-shadow:0 6px 18px rgba(34,197,94,.2); }
        .category-icon { font-size:32px; margin-bottom:8px; }
        .category-card h3 { font-size:13px; color:#1f2937; font-weight:600; }

        /* ===== SECTION HEADER ===== */
        .section-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; }
        .section-title  { font-size:22px; color:#1f2937; font-weight:700; }
        .section-link   { font-size:14px; color:var(--brand-navy); text-decoration:none; font-weight:600; }
        .section-link:hover { text-decoration:underline; }

        /* ===== PRODUTOS ===== */
        .featured-section { padding:40px 0; }
        .products-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:16px; }
        .product-card { background:white; border-radius:10px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.08); transition:all .25s; }
        .product-card:hover { transform:translateY(-6px); box-shadow:0 8px 24px rgba(0,0,0,.13); }
        .product-image { width:100%; height:200px; background:#f3f4f6; display:flex; align-items:center; justify-content:center; overflow:hidden; }
        .product-image img { width:100%; height:100%; object-fit:cover; transition:transform .3s; }
        .product-card:hover .product-image img { transform:scale(1.05); }
        .product-image-placeholder { width:100%; height:100%; background:linear-gradient(135deg,#e5e7eb,#d1d5db); display:flex; align-items:center; justify-content:center; font-size:11px; color:#9ca3af; text-transform:uppercase; letter-spacing:.08em; }
        .product-info { padding:14px; }
        .product-name { font-size:13px; font-weight:600; color:#1f2937; margin-bottom:8px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        .product-price { font-size:16px; font-weight:700; color:var(--brand-navy); margin-bottom:12px; }
        .product-btn { width:100%; padding:10px; background:var(--brand-green); color:white; border:none; border-radius:6px; cursor:pointer; font-weight:600; font-size:13px; transition:background .2s; }
        .product-btn:hover { background:#16a34a; }
        .product-btn:active { transform:scale(.98); }
        .no-products { grid-column:1/-1; text-align:center; padding:60px 20px; color:#6b7280; }
        .no-products a { color:var(--brand-navy); font-weight:600; }

        /* ===== PROMO ===== */
        .promo-banner { background:linear-gradient(135deg,var(--brand-navy),#2563eb); color:white; padding:40px 24px; border-radius:12px; text-align:center; margin:40px 0; }
        .promo-banner h2 { font-size:24px; margin-bottom:8px; }
        .promo-banner p  { font-size:15px; margin-bottom:16px; opacity:.9; }
        .promo-code { display:inline-block; background:rgba(255,255,255,.2); border:2px dashed rgba(255,255,255,.6); padding:6px 20px; border-radius:6px; font-weight:700; letter-spacing:2px; font-size:20px; margin-bottom:20px; }

        /* ===== NEWSLETTER ===== */
        .newsletter-section { background:#f9fafb; padding:40px 0; }
        .newsletter-content { max-width:480px; margin:0 auto; text-align:center; }
        .newsletter-content h2 { font-size:22px; margin-bottom:8px; }
        .newsletter-content p  { margin-bottom:20px; color:#6b7280; }
        .newsletter-form { display:flex; flex-direction:column; gap:10px; }
        .newsletter-form input { padding:13px 16px; border:2px solid #e5e7eb; border-radius:6px; font-size:14px; }
        .newsletter-form input:focus { outline:none; border-color:var(--brand-navy); }
        .newsletter-form button { padding:13px; background:var(--brand-navy); color:white; border:none; border-radius:6px; font-weight:600; cursor:pointer; transition:background .2s; }
        .newsletter-form button:hover { background:var(--brand-dark); }

        /* ===== TOAST ===== */
        .toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(80px); background:#1f2937; color:white; padding:13px 24px; border-radius:8px; font-size:14px; font-weight:500; z-index:9999; transition:transform .3s ease; white-space:nowrap; pointer-events:none; }
        .toast.show { transform:translateX(-50%) translateY(0); }

        /* ===== RESPONSIVO ===== */
        @media (max-width:767px) {
            .banner-slider { height:220px; }
            .banner-slide h2 { font-size:20px; }
            .banner-slide p  { font-size:13px; margin-bottom:14px; }
            .section-title { font-size:18px; }
            .promo-banner h2 { font-size:18px; }
        }
        @media (min-width:768px) {
            .banner-slider { height:360px; }
            .banner-slide h2 { font-size:36px; }
            .categories-grid { grid-template-columns:repeat(6,1fr); }
            .products-grid   { grid-template-columns:repeat(4,1fr); }
            .newsletter-form { flex-direction:row; }
            .newsletter-form input { flex:1; }
        }
        @media (min-width:1025px) {
            .banner-slider { height:420px; }
            .banner-slide h2 { font-size:44px; }
            .section-title  { font-size:26px; }
            .product-image  { height:220px; }
        }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
    <div class="container">
        <div class="navbar-brand">
            <a href="/" style="display:flex;align-items:center;text-decoration:none;">
                <img src="/images/logo.svg" alt="ShopVivaliz" style="height:46px;width:auto;"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                <span style="display:none;font-size:20px;font-weight:700;color:var(--brand-navy);">ShopVivaliz</span>
            </a>
        </div>
        <button class="menu-toggle" id="menuToggle" aria-label="Menu">☰</button>
        <div class="navbar-menu" id="navMenu">
            <a href="/">Home</a>
            <a href="/catalogo">Catálogo</a>
            <a href="/sobre">Sobre</a>
            <a href="/contato">Contato</a>
            <a href="/carrinho">🛒 Carrinho <span id="cartCount" style="display:none;background:#ef4444;color:white;border-radius:999px;padding:1px 7px;font-size:11px;"></span></a>
        </div>
    </div>
</nav>

<!-- Banner Slider -->
<section class="banner-slider" aria-label="Banners">
    <div class="banner-slide active">
        <div><h2>Bem-vindo ao ShopVivaliz</h2><p>Produtos de qualidade com entrega rápida e segura</p><a href="/catalogo" class="banner-btn">Ver Catálogo</a></div>
    </div>
    <div class="banner-slide">
        <div><h2>Promoção 50% OFF</h2><p>Em produtos selecionados — só esta semana</p><a href="/catalogo?promo=1" class="banner-btn" style="color:#065f46;">Ver Promoções</a></div>
    </div>
    <div class="banner-slide">
        <div><h2>Frete Grátis</h2><p>Em compras acima de R$ 100 para todo o Brasil</p><a href="/catalogo" class="banner-btn" style="color:#4f46e5;">Aproveitar</a></div>
    </div>
    <button class="banner-arrow prev" onclick="moveSlide(-1)" aria-label="Anterior">‹</button>
    <button class="banner-arrow next" onclick="moveSlide(1)" aria-label="Próximo">›</button>
    <div class="banner-dots">
        <button class="dot active" onclick="goToSlide(0)"></button>
        <button class="dot" onclick="goToSlide(1)"></button>
        <button class="dot" onclick="goToSlide(2)"></button>
    </div>
</section>

<!-- Busca -->
<section class="container">
    <form class="search-bar" action="/catalogo" method="get" role="search">
        <input type="text" name="q" placeholder="Buscar produtos, marcas ou categorias..." autocomplete="off">
        <button type="submit">🔍 Buscar</button>
    </form>
</section>

<!-- Categorias -->
<section class="categories-section">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">Categorias</h2>
            <a href="/catalogo" class="section-link">Ver tudo →</a>
        </div>
        <div class="categories-grid">
            <a href="/catalogo?categoria=roupas"     class="category-card"><div class="category-icon">👕</div><h3>Roupas</h3></a>
            <a href="/catalogo?categoria=calcados"   class="category-card"><div class="category-icon">👟</div><h3>Calçados</h3></a>
            <a href="/catalogo?categoria=acessorios" class="category-card"><div class="category-icon">💎</div><h3>Acessórios</h3></a>
            <a href="/catalogo?categoria=casa"       class="category-card"><div class="category-icon">🏠</div><h3>Casa</h3></a>
            <a href="/catalogo?categoria=esportes"   class="category-card"><div class="category-icon">⚽</div><h3>Esportes</h3></a>
            <a href="/catalogo?categoria=eletronicos" class="category-card"><div class="category-icon">📱</div><h3>Eletrônicos</h3></a>
        </div>
    </div>
</section>

<!-- Produtos em Destaque -->
<section class="featured-section">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">Produtos em Destaque</h2>
            <a href="/catalogo" class="section-link">Ver todos →</a>
        </div>
        <div class="products-grid">
            <?php if ($has_products): ?>
                <?php foreach ($featured_products as $p): ?>
                    <?php
                        $id    = htmlspecialchars((string)($p['id'] ?? ''), ENT_QUOTES);
                        $sku   = htmlspecialchars((string)($p['sku'] ?? ''), ENT_QUOTES);
                        $name  = htmlspecialchars((string)($p['name'] ?? 'Produto'), ENT_QUOTES);
                        $price = isset($p['price']) && $p['price'] > 0
                            ? 'R$ ' . number_format((float)$p['price'], 2, ',', '.') : '';
                        $raw_img = trim((string)($p['image_url'] ?? $p['primary_image_url'] ?? ''));
                        if ($raw_img !== '' && str_starts_with($raw_img, '//')) $raw_img = 'https:' . $raw_img;
                        $img = htmlspecialchars($raw_img, ENT_QUOTES);
                    ?>
                    <div class="product-card">
                        <div class="product-image">
                            <?php if ($img !== ''): ?>
                                <img src="<?= $img ?>" alt="<?= $name ?>" loading="lazy"
                                     onerror="this.parentElement.innerHTML='<div class=product-image-placeholder>Sem imagem</div>'">
                            <?php else: ?>
                                <div class="product-image-placeholder">Sem imagem</div>
                            <?php endif; ?>
                        </div>
                        <div class="product-info">
                            <div class="product-name"><?= $name ?></div>
                            <?php if ($price): ?><div class="product-price"><?= $price ?></div><?php endif; ?>
                            <button class="product-btn"
                                data-id="<?= $id ?>" data-sku="<?= $sku ?>"
                                data-name="<?= $name ?>"
                                data-price="<?= htmlspecialchars((string)($p['price'] ?? 0), ENT_QUOTES) ?>"
                                data-image="<?= $img ?>">
                                🛒 Adicionar ao Carrinho
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-products">
                    <p style="font-size:16px;margin-bottom:12px;">Nosso catálogo está sendo atualizado.</p>
                    <a href="/catalogo">Ver catálogo completo →</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Promoção -->
<section class="container">
    <div class="promo-banner">
        <h2>🎉 Oferta Especial de Boas-Vindas</h2>
        <p>20% de desconto na sua primeira compra</p>
        <div class="promo-code">BEMVINDO20</div><br>
        <a href="/catalogo" class="banner-btn" style="margin-top:4px;color:var(--brand-navy);">Usar Cupom Agora</a>
    </div>
</section>

<!-- Newsletter -->
<section class="newsletter-section">
    <div class="container">
        <div class="newsletter-content">
            <h2>📬 Fique por Dentro</h2>
            <p>Receba ofertas exclusivas e novidades direto no seu e-mail</p>
            <form class="newsletter-form" id="newsletterForm">
                <input type="email" name="email" placeholder="Seu melhor e-mail" required autocomplete="email">
                <button type="submit">Inscrever</button>
            </form>
        </div>
    </div>
</section>

<!-- Footer -->
<footer>
    <div class="container">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:28px;margin-bottom:28px;text-align:left;">
            <div>
                <h3 style="margin-bottom:12px;font-size:15px;">Sobre</h3>
                <ul style="list-style:none;padding:0;display:flex;flex-direction:column;gap:8px;">
                    <li><a href="/sobre" style="color:#ccc;text-decoration:none;font-size:13px;">Sobre Nós</a></li>
                    <li><a href="/blog"  style="color:#ccc;text-decoration:none;font-size:13px;">Blog</a></li>
                </ul>
            </div>
            <div>
                <h3 style="margin-bottom:12px;font-size:15px;">Compras</h3>
                <ul style="list-style:none;padding:0;display:flex;flex-direction:column;gap:8px;">
                    <li><a href="/catalogo"       style="color:#ccc;text-decoration:none;font-size:13px;">Catálogo</a></li>
                    <li><a href="/catalogo?promo=1" style="color:#ccc;text-decoration:none;font-size:13px;">Promoções</a></li>
                    <li><a href="/carrinho"        style="color:#ccc;text-decoration:none;font-size:13px;">Carrinho</a></li>
                </ul>
            </div>
            <div>
                <h3 style="margin-bottom:12px;font-size:15px;">Suporte</h3>
                <ul style="list-style:none;padding:0;display:flex;flex-direction:column;gap:8px;">
                    <li><a href="/contato"              style="color:#ccc;text-decoration:none;font-size:13px;">Contato</a></li>
                    <li><a href="/faq"                  style="color:#ccc;text-decoration:none;font-size:13px;">FAQ</a></li>
                    <li><a href="/politica-privacidade" style="color:#ccc;text-decoration:none;font-size:13px;">Privacidade</a></li>
                </ul>
            </div>
        </div>
        <div style="border-top:1px solid #444;padding-top:18px;text-align:center;">
            <p style="font-size:13px;">&copy; 2026 ShopVivaliz. Todos os direitos reservados.</p>
            <p style="font-size:11px;margin-top:8px;color:#999;">Entrega Rápida · Compra Segura</p>
        </div>
    </div>
</footer>

<div class="toast" id="toast"></div>

<script>
    function showToast(msg, ms=2800) {
        const t=document.getElementById('toast'); t.textContent=msg;
        t.classList.add('show'); setTimeout(()=>t.classList.remove('show'),ms);
    }
    function getCart()  { try{return JSON.parse(localStorage.getItem('svCart')||'[]');}catch{return[];} }
    function saveCart(c){ localStorage.setItem('svCart',JSON.stringify(c)); updateBadge(); }
    function updateBadge() {
        const n=getCart().reduce((s,i)=>s+(i.qty||1),0);
        const el=document.getElementById('cartCount');
        if(el){el.textContent=n;el.style.display=n>0?'inline':'none';}
    }
    function addToCart(p) {
        const cart=getCart(), idx=cart.findIndex(i=>i.id===p.id);
        if(idx>=0) cart[idx].qty=(cart[idx].qty||1)+1; else cart.push({...p,qty:1});
        saveCart(cart); showToast('✓ '+p.name+' adicionado ao carrinho!');
    }
    document.querySelectorAll('.product-btn').forEach(btn=>{
        btn.addEventListener('click',function(){
            addToCart({id:this.dataset.id,sku:this.dataset.sku,name:this.dataset.name,
                price:parseFloat(this.dataset.price)||0,image:this.dataset.image});
        });
    });
    updateBadge();

    let slideIdx=0;
    const slides=document.querySelectorAll('.banner-slide'), dots=document.querySelectorAll('.dot');
    let timer;
    function showSlide(n){
        slideIdx=((n%slides.length)+slides.length)%slides.length;
        slides.forEach((s,i)=>s.classList.toggle('active',i===slideIdx));
        dots.forEach((d,i)=>d.classList.toggle('active',i===slideIdx));
    }
    function moveSlide(dir){clearInterval(timer);showSlide(slideIdx+dir);startAuto();}
    function goToSlide(n)  {clearInterval(timer);showSlide(n);startAuto();}
    function startAuto()   {timer=setInterval(()=>showSlide(slideIdx+1),5000);}
    startAuto();
    let tx=0; const sl=document.querySelector('.banner-slider');
    sl.addEventListener('touchstart',e=>{tx=e.touches[0].clientX;},{passive:true});
    sl.addEventListener('touchend',e=>{const dx=e.changedTouches[0].clientX-tx;if(Math.abs(dx)>40)moveSlide(dx<0?1:-1);});

    const toggle=document.getElementById('menuToggle'), nav=document.getElementById('navMenu');
    toggle.addEventListener('click',()=>nav.classList.toggle('active'));
    nav.querySelectorAll('a').forEach(a=>a.addEventListener('click',()=>nav.classList.remove('active')));

    document.getElementById('newsletterForm').addEventListener('submit',function(e){
        e.preventDefault();
        const email=this.querySelector('input[type=email]').value.trim();
        if(!email)return; showToast('📬 '+email+' inscrito com sucesso!'); this.reset();
    });
</script>
</body>
</html><?php // fim
