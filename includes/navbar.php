<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$svNavCurrent = $svNavCurrent ?? trim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
$svNavCurrent = preg_replace('#^index\.php$#', '', $svNavCurrent);

$svNavLinks = [
    ['href' => '/', 'label' => 'Home', 'match' => ['']],
    ['href' => '/catalogo', 'label' => 'Catálogo', 'match' => ['catalogo', 'produtos', 'produto']],
    ['href' => '/sobre', 'label' => 'Sobre', 'match' => ['sobre']],
    ['href' => '/contato', 'label' => 'Contato', 'match' => ['contato']],
    ['href' => '/carrinho', 'label' => 'Carrinho', 'match' => ['carrinho', 'checkout']],
];

$svLoggedIn = !empty($_SESSION['user_id']);
$svUserName = trim((string)($_SESSION['user_name'] ?? ''));
$svUserFirstName = $svUserName !== '' ? explode(' ', $svUserName)[0] : 'Minha conta';
$svIsHome = $svNavCurrent === '';
$svIsProduct = $svNavCurrent === 'produto';
$svIsCheckout = $svNavCurrent === 'checkout';
$svIsCart = $svNavCurrent === 'carrinho';
$svIsCatalog = in_array($svNavCurrent, ['catalogo', 'produtos', 'produto'], true);
?>
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0b4f88">
<link rel="preconnect" href="https://s3.amazonaws.com">
<link rel="dns-prefetch" href="https://s3.amazonaws.com">
<link rel="dns-prefetch" href="https://images.unsplash.com">
<link rel="stylesheet" href="/css/shopvivaliz-visual-v3.css?v=3.0.0">
<link rel="stylesheet" href="/css/accessibility-v11.css?v=11.0.0">
<link rel="stylesheet" href="/css/loading-states-v21.css?v=21.0.0">
<link rel="stylesheet" href="/css/network-status-v22.css?v=22.0.0">
<link rel="stylesheet" href="/css/print-v27.css?v=27.0.0" media="print">
<link rel="stylesheet" href="/css/premium-theme.css?v=2026-07-12">
<link rel="stylesheet" href="/css/dazzle-v1.css?v=1.2.0">
<?php if ($svIsHome): ?><link rel="stylesheet" href="/css/home-polish-v17.css?v=17.0.0"><link rel="stylesheet" href="/css/category-real-images-v52.css?v=52.0.0"><?php endif; ?>
<?php if ($svIsCatalog): ?><link rel="stylesheet" href="/css/catalog-conversion-v4.css?v=4.0.0"><link rel="stylesheet" href="/css/product-image-integrity-v63.css?v=63.0.0"><link rel="stylesheet" href="/css/price-integrity-v73.css?v=73.0.0"><link rel="stylesheet" href="/css/stock-integrity-v83.css?v=83.0.0"><?php endif; ?>
<?php if ($svIsProduct): ?><link rel="stylesheet" href="/css/product-conversion-v5.css?v=5.0.0"><link rel="stylesheet" href="/css/product-image-integrity-v63.css?v=63.0.0"><link rel="stylesheet" href="/css/price-integrity-v73.css?v=73.0.0"><link rel="stylesheet" href="/css/stock-integrity-v83.css?v=83.0.0"><?php endif; ?>
<?php if ($svIsCart || $svIsCheckout): ?><link rel="stylesheet" href="/css/cart-integrity-v94.css?v=94.0.0"><?php endif; ?>
<?php if ($svIsCart): ?><link rel="stylesheet" href="/css/cart-polish-v14.css?v=14.0.0"><?php endif; ?>
<?php if ($svIsCheckout): ?><link rel="stylesheet" href="/css/checkout-conversion-v6.css?v=6.0.0"><?php endif; ?>
<?php if ($svIsCart || $svIsCheckout): ?><link rel="stylesheet" href="/css/shipping-v7.css?v=7.0.0"><?php endif; ?>
<a class="sv-skip-link" href="#conteudo-principal">Pular para o conteúdo</a>
<header class="navbar sv-navbar"><nav class="container nav-inner" aria-label="Navegação principal"><a href="/" class="brand-link" aria-label="Ir para a home da Vivaliz"><img src="/images/logo-vivaliz.png" alt="Vivaliz" class="brand-logo-img" width="210" height="46" decoding="async" onerror="this.src='/images/logo-vivaliz-square.png'"></a><button class="menu-toggle" id="menuToggle" type="button" aria-expanded="false" aria-controls="navMenu" aria-label="Abrir menu"><span aria-hidden="true">☰</span></button><div class="navbar-menu" id="navMenu"><?php foreach ($svNavLinks as $link): ?><?php $isCurrent = in_array($svNavCurrent, $link['match'], true); ?><a href="<?= htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?><?= $link['href'] === '/catalogo' ? ' class="sv-nav-cta"' : '' ?><?= $link['href'] === '/carrinho' ? ' id="nav-cart-link" class="nav-cart-link"' : '' ?>><?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?><?php if ($link['href'] === '/carrinho'): ?><span class="cart-badge" id="nav-cart-count" aria-live="polite"></span><?php endif; ?></a><?php endforeach; ?><?php if ($svLoggedIn): ?><a href="/meus-pedidos.php" class="nav-account-link">👤 <?= htmlspecialchars($svUserFirstName, ENT_QUOTES, 'UTF-8') ?></a><a href="/auth/logout.php">Sair</a><?php else: ?><a href="/auth/login.php" class="nav-account-link">Entrar</a><?php endif; ?></div></nav></header>

<div class="sv-live-region" id="svLiveRegion" aria-live="polite"></div>
<script>(function(){var main=document.querySelector('main');if(main&&!main.id)main.id='conteudo-principal';var menuToggle=document.getElementById('menuToggle');var navMenu=document.getElementById('navMenu');if(menuToggle&&navMenu){menuToggle.addEventListener('click',function(){var isOpen=navMenu.classList.toggle('active');menuToggle.setAttribute('aria-expanded',isOpen?'true':'false');menuToggle.setAttribute('aria-label',isOpen?'Fechar menu':'Abrir menu');});}if('serviceWorker' in navigator&&location.protocol==='https:'){window.addEventListener('load',function(){navigator.serviceWorker.register('/service-worker.js').catch(function(){});});}})();</script>
<script src="/js/cart-persistence-v23.js?v=23.0.0" defer></script><script src="/js/shopvivaliz-visual-v3.js?v=3.0.0" defer></script><script src="/js/dazzle-v1.js?v=1.2.0" defer></script><script src="/js/performance-v12.js?v=12.0.0" defer></script><script src="/js/offline-status-v22.js?v=22.0.0" defer></script><script src="/js/storefront-events-v26.js?v=26.0.0" defer></script><script src="/js/install-prompt-v29.js?v=29.0.0" defer></script><script src="/js/cro-interactions.js?v=2026-07-12" defer></script>
<?php if ($svIsHome): ?><script src="/js/category-real-images-v52.js?v=52.0.0" defer></script><?php endif; ?>
<?php if ($svIsCatalog): ?><script src="/js/catalog-conversion-v4.js?v=4.0.0" defer></script><script src="/js/search-enhancements-v25.js?v=25.0.0" defer></script><script src="/js/catalog-image-integrity-v62.js?v=62.0.0" defer></script><script src="/js/catalog-price-integrity-v72.js?v=72.0.0" defer></script><script src="/js/catalog-stock-integrity-v82.js?v=82.0.0" defer></script><?php endif; ?>
<?php if ($svIsProduct): ?><script src="/js/product-conversion-v5.js?v=5.0.0" defer></script><script src="/js/product-schema-v16.js?v=16.0.0" defer></script><script src="/js/recently-viewed-v24.js?v=24.0.0" defer></script><script src="/js/product-image-integrity-v63.js?v=63.0.0" defer></script><script src="/js/product-price-integrity-v73.js?v=73.0.0" defer></script><script src="/js/product-stock-integrity-v83.js?v=83.0.0" defer></script><?php endif; ?>
<?php if ($svIsCart): ?><script src="/js/cart-shipping-v7.js?v=7.0.0" defer></script><script src="/js/cart-server-validation-v92.js?v=92.0.0" defer></script><?php endif; ?>
<?php if ($svIsCheckout): ?><script src="/js/checkout-conversion-v6.js?v=6.0.0" defer></script><script src="/js/checkout-resilience-v15.js?v=15.0.0" defer></script><script src="/js/checkout-shipping-v7.js?v=7.0.0" defer></script><script src="/js/checkout-cart-freshness-v93.js?v=93.0.0" defer></script><script src="/js/checkout-idempotency-v122.js?v=122.0.0" defer></script><?php endif; ?>

<!-- Mini-Cart Side Drawer -->
<div class="mini-cart-overlay" id="mini-cart-overlay"></div>
<div class="mini-cart-drawer" id="mini-cart-drawer">
    <div class="mini-cart-header">
        <h3>Seu Carrinho</h3>
        <button id="mini-cart-close" aria-label="Fechar carrinho">&times;</button>
    </div>
    <div class="mini-cart-body" id="mini-cart-body">
        <!-- Itens injetados via JS -->
    </div>
    <div class="mini-cart-footer">
        <div class="mini-cart-subtotal">
            <span>Subtotal:</span>
            <strong id="mini-cart-total-value">R$ 0,00</strong>
        </div>
        <div class="free-shipping-progress-wrapper" style="margin-bottom:10px;">
            <div class="free-shipping-progress-bar" id="mini-cart-shipping-bar"></div>
        </div>
        <p class="free-shipping-text" id="mini-cart-shipping-text" style="font-size:12px;text-align:center;margin-bottom:10px;"></p>
        <a href="/carrinho" class="btn btn-primary btn-large" style="width:100%; display:block; text-align:center;">Ir para o Checkout</a>
    </div>
</div>

<!-- Liz Assistant Premium Mascot Widget -->
<link rel="stylesheet" href="/public/assets/liz-assistant/liz-assistant.css">
<script src="/public/assets/liz-assistant/liz-assistant.js" defer></script>
