<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$svNavCurrent = $svNavCurrent ?? trim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
$svNavCurrent = preg_replace('#^index\.php$#', '', $svNavCurrent);

$svNavLinks = [
    ['href' => '/', 'label' => 'Home', 'match' => ['']],
    ['href' => '/catalogo', 'label' => 'Produtos', 'match' => ['catalogo', 'produtos', 'produto']],
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
$svCompanyProfile = @include dirname(__DIR__) . '/config/company-profile.php';
$svWhatsappRaw = is_array($svCompanyProfile) ? (string)($svCompanyProfile['social_media']['whatsapp'] ?? '') : '';
$svWhatsappDigits = preg_replace('/\D+/', '', $svWhatsappRaw);
$svWhatsappMessage = rawurlencode('Ola! Vim pelo site da ShopVivaliz e gostaria de falar com a equipe.');
$svWhatsappLink = $svWhatsappDigits !== '' ? "https://wa.me/{$svWhatsappDigits}?text={$svWhatsappMessage}" : '/contato';
$svRecoveryCoupon = function_exists('svcp_builtin_coupons') ? svcp_builtin_coupons('VOLTEI5') : null;
$svRecoveryCouponCode = is_array($svRecoveryCoupon) ? (string)($svRecoveryCoupon['code'] ?? 'VOLTEI5') : 'VOLTEI5';
$svRecoveryCouponLabel = is_array($svRecoveryCoupon) ? (string)($svRecoveryCoupon['label'] ?? 'Desconto 5%') : 'Desconto 5%';
?>
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0b4f88">
<style>body { opacity: 1 !important; visibility: visible !important; }</style>
<link rel="preconnect" href="https://s3.amazonaws.com">
<link rel="dns-prefetch" href="https://s3.amazonaws.com">
<link rel="dns-prefetch" href="https://images.unsplash.com">
<link rel="stylesheet" href="/css/shopvivaliz-unified-theme.css?v=2026-07-18">
<!-- accessibility-v11.css bundled -->
<!-- loading-states-v21.css bundled -->
<!-- network-status-v22.css bundled -->
<link rel="stylesheet" href="/css/print-v27.css?v=27.0.0" media="print">
<!-- premium-theme.css bundled -->
<!-- premium-visual-v2.css bundled -->
<!-- dazzle-v1.css bundled -->
<?php if ($svIsHome): ?><link rel="stylesheet" href="/css/home-polish-v17.css?v=17.0.0"><link rel="stylesheet" href="/css/category-real-images-v52.css?v=52.0.0"><?php endif; ?>
<?php if ($svIsCatalog): ?><link rel="stylesheet" href="/css/catalog-conversion-v4.css?v=4.0.0"><link rel="stylesheet" href="/css/product-image-integrity-v63.css?v=63.0.0"><link rel="stylesheet" href="/css/price-integrity-v73.css?v=73.0.0"><link rel="stylesheet" href="/css/stock-integrity-v83.css?v=83.0.0"><?php endif; ?>
<?php if ($svIsProduct): ?><link rel="stylesheet" href="/css/product-conversion-v5.css?v=5.0.0"><link rel="stylesheet" href="/css/product-image-integrity-v63.css?v=63.0.0"><link rel="stylesheet" href="/css/price-integrity-v73.css?v=73.0.0"><link rel="stylesheet" href="/css/stock-integrity-v83.css?v=83.0.0"><?php endif; ?>
<?php if ($svIsCart || $svIsCheckout): ?><link rel="stylesheet" href="/css/cart-integrity-v94.css?v=94.0.0"><?php endif; ?>
<?php if ($svIsCart): ?><link rel="stylesheet" href="/css/cart-polish-v14.css?v=14.0.0"><?php endif; ?>
<?php if ($svIsCheckout): ?><link rel="stylesheet" href="/css/checkout-conversion-v6.css?v=6.0.0"><?php endif; ?>
<?php if ($svIsCart || $svIsCheckout): ?><link rel="stylesheet" href="/css/shipping-v7.css?v=7.0.0"><?php endif; ?>
<a class="sv-skip-link" href="#conteudo-principal">Pular para o conteúdo</a>
<div class="sv-announcement-bar" style="background: linear-gradient(90deg, #07345d, #0b4f88, #07345d); color: #ffffff; text-align: center; padding: 7px 14px; font-size: 12px; font-weight: 700; letter-spacing: 0.03em; border-bottom: 1px solid rgba(255,255,255,0.15);">
    <span>🚚 FRETE GRÁTIS ACIMA DE R$ 199 | 🎁 5% OFF NA 1ª COMPRA COM O CUPOM <strong style="color: #35c759; background: rgba(255,255,255,0.15); padding: 2px 6px; border-radius: 4px;">VOLTEI5</strong></span>
</div>
<header class="navbar sv-navbar"><nav class="container nav-inner" aria-label="Navegação principal">
        <div class="nav-icons">
            <button class="sv-theme-toggle" id="theme-toggle" aria-label="Alternar tema">
                <svg viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
            </button>
        </div>
<a href="/" class="brand-link" aria-label="Ir para a home da Vivaliz"><img src="/images/logo-vivaliz.png" alt="Vivaliz" class="brand-logo-img" width="210" height="46" decoding="async" onerror="this.src='/images/logo-vivaliz-square.png'"></a><button class="menu-toggle" id="menuToggle" type="button" aria-expanded="false" aria-controls="navMenu" aria-label="Abrir menu"><span aria-hidden="true">☰</span></button><div class="navbar-menu" id="navMenu"><?php foreach ($svNavLinks as $link): ?><?php $isCurrent = in_array($svNavCurrent, $link['match'], true); ?><a href="<?= htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?><?= $link['href'] === '/catalogo' ? ' class="sv-nav-cta"' : '' ?><?= $link['href'] === '/carrinho' ? ' id="nav-cart-link" class="nav-cart-link"' : '' ?>><?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?><?php if ($link['href'] === '/carrinho'): ?><span class="cart-badge" id="nav-cart-count" aria-live="polite"></span><?php endif; ?></a><?php endforeach; ?><?php if ($svLoggedIn): ?><a href="/minha-conta/" class="nav-account-link">👤 <?= htmlspecialchars($svUserFirstName, ENT_QUOTES, 'UTF-8') ?></a><a href="/auth/logout.php">Sair</a><?php else: ?><a href="/auth/login.php" class="nav-account-link">Entrar</a><?php endif; ?></div></nav></header>

<div class="sv-live-region" id="svLiveRegion" aria-live="polite"></div>
<script>(function(){var main=document.querySelector('main');if(main&&!main.id)main.id='conteudo-principal';var menuToggle=document.getElementById('menuToggle');var navMenu=document.getElementById('navMenu');if(menuToggle&&navMenu){menuToggle.addEventListener('click',function(){var isOpen=navMenu.classList.toggle('active');menuToggle.setAttribute('aria-expanded',isOpen?'true':'false');menuToggle.setAttribute('aria-label',isOpen?'Fechar menu':'Abrir menu');});}if('serviceWorker' in navigator&&location.protocol==='https:'){window.addEventListener('load',function(){navigator.serviceWorker.register('/service-worker.js').catch(function(){});});}})();</script>
<script src="/js/cart-persistence-v23.js?v=23.0.0" defer></script><script src="/js/shopvivaliz-visual-v3.js?v=3.0.0" defer></script><script src="/js/dazzle-v1.js?v=1.2.0" defer></script><script src="/js/performance-v12.js?v=12.0.0" defer></script><script src="/js/offline-status-v22.js?v=22.0.0" defer></script><script src="/js/storefront-events-v26.js?v=26.0.0" defer></script><script src="/js/install-prompt-v29.js?v=29.0.0" defer></script><script src="/js/cro-interactions.js?v=2026-07-12" defer></script>
<?php if ($svIsHome): ?><script src="/js/category-real-images-v52.js?v=52.0.0" defer></script><?php endif; ?>
<?php if ($svIsCatalog): ?><script src="/js/catalog-conversion-v4.js?v=4.0.0" defer></script><script src="/js/search-enhancements-v25.js?v=25.0.0" defer></script><script src="/js/catalog-image-integrity-v62.js?v=62.0.0" defer></script><script src="/js/catalog-price-integrity-v72.js?v=72.0.0" defer></script><script src="/js/catalog-stock-integrity-v82.js?v=82.0.0" defer></script><?php endif; ?>
<?php if ($svIsProduct): ?><script src="/js/product-conversion-v5.js?v=5.0.0" defer></script><script src="/js/product-schema-v16.js?v=16.0.0" defer></script><script src="/js/recently-viewed-v24.js?v=24.0.0" defer></script><script src="/js/product-image-integrity-v63.js?v=63.0.0" defer></script><script src="/js/product-price-integrity-v73.js?v=73.0.0" defer></script><script src="/js/product-stock-integrity-v83.js?v=83.0.0" defer></script><?php endif; ?>
<?php if ($svIsCart): ?><script src="/js/cart-shipping-v7.js?v=7.0.0" defer></script><script src="/js/cart-server-validation-v92.js?v=92.0.0" defer></script><?php endif; ?>
<?php if ($svIsCheckout): ?><script src="/js/checkout-conversion-v6.js?v=6.1.0" defer></script><script src="/js/checkout-resilience-v15.js?v=15.1.0" defer></script><script src="/js/checkout-shipping-v7.js?v=7.0.0" defer></script><script src="/js/checkout-cart-freshness-v94.js?v=94.0.0" defer></script><script src="/js/checkout-idempotency-v122.js?v=122.0.0" defer></script><?php endif; ?>

<style>
.sv-whatsapp-float{
    position:fixed;
    left:20px;
    bottom:20px;
    z-index:1200;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:60px;
    height:60px;
    padding:0;
    border-radius:999px;
    background:#25d366;
    color:#fff;
    text-decoration:none;
    font-weight:800;
    box-shadow:0 16px 36px rgba(8, 15, 33, 0.22);
    transition:transform .18s ease, box-shadow .18s ease, background .18s ease;
}
.sv-whatsapp-float:hover{
    transform:translateY(-2px);
    background:#1fb85a;
    box-shadow:0 20px 40px rgba(8, 15, 33, 0.26);
}
.sv-whatsapp-float:focus-visible{
    outline:3px solid rgba(37,211,102,.28);
    outline-offset:3px;
}
.sv-whatsapp-float__icon{
    width:34px;
    height:34px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}
.sv-whatsapp-float__icon svg {
    width: 100%;
    height: 100%;
    fill: #ffffff;
}
.sv-whatsapp-float__text{
    display:flex;
    flex-direction:column;
    line-height:1.05;
}
.sv-whatsapp-float__text small{
    font-size:11px;
    font-weight:700;
    opacity:.9;
}
.sv-whatsapp-float__text strong{
    font-size:14px;
    font-weight:800;
}
@media (max-width: 640px){
    .sv-whatsapp-float{
        left:14px;
        bottom:14px;
        width:52px;
        height:52px;
    }
    .sv-whatsapp-float__text small{
        display:none;
    }
}
</style>

<?php if (getenv('TAG_MANAGER')): ?>
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?= htmlspecialchars(getenv('TAG_MANAGER')) ?>"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
<?php endif; ?>

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
        <!-- Multi-Level Gamification Rewards Progress Track -->
        <div class="gamification-rewards-container" style="margin-top: 10px; margin-bottom: 12px; font-family:'Inter',sans-serif;">
            <div class="free-shipping-text" id="mini-cart-shipping-text" style="font-size:12px; text-align:center; margin-bottom:8px; line-height: 1.4; color: #475569;">Calculando suas recompensas...</div>
            <div class="free-shipping-progress-wrapper" style="position:relative; height:8px; background:#e2e8f0; border-radius:999px; overflow:visible; margin-bottom:12px;">
                <div class="free-shipping-progress-bar" id="mini-cart-shipping-bar" style="position:absolute; left:0; top:0; height:100%; width:0%; background:linear-gradient(90deg, #3b82f6, #10b981); border-radius:999px; transition:width 0.3s ease;"></div>
                <!-- Goal 1 Marker: R$ 150 -->
                <div class="gamification-goal-marker" id="goal-150-marker" title="Meta R$ 150: Cupom VIVALIZ5"
                     style="position:absolute; left:50%; top:-4px; width:16px; height:16px; border-radius:50%; background:#fff; border:2.5px solid #cbd5e1; transform:translateX(-50%); display:flex; align-items:center; justify-content:center; font-size:8px; z-index:2; transition:all 0.3s; cursor:pointer;">🎁</div>
                <!-- Goal 2 Marker: Frete Gratis (visibilidade controlada via JS conforme configuracao no admin) -->
                <div class="gamification-goal-marker" id="goal-299-marker" data-free-shipping-marker="1" title="Frete Grátis"
                     style="position:absolute; right:0; top:-4px; width:16px; height:16px; border-radius:50%; background:#fff; border:2.5px solid #cbd5e1; display:none; align-items:center; justify-content:center; font-size:8px; z-index:2; transition:all 0.3s; cursor:pointer;">🚚</div>
            </div>
        </div>
        <a href="/carrinho" class="btn btn-primary btn-large" style="width:100%; display:block; text-align:center;">Ir para o Checkout</a>
    </div>
</div>

<!-- Mobile Sticky Bottom Navigation Bar -->
<div class="sv-mobile-nav-bar" role="navigation" aria-label="Navegação mobile rápida">
    <a href="/" class="<?= $svNavCurrent === 'home' ? 'active' : '' ?>" aria-label="Ir para a home">
        <span class="nav-icon">🏠</span>
        <span class="nav-label">Início</span>
    </a>
    <a href="/catalogo" class="<?= $svNavCurrent === 'catalogo' ? 'active' : '' ?>" aria-label="Ver catálogo">
        <span class="nav-icon">🔍</span>
        <span class="nav-label">Buscar</span>
    </a>
    <a href="#" onclick="if(window.openMiniCart){window.openMiniCart(); return false;}else{window.location.href='/carrinho'; return false;}" aria-label="Abrir carrinho">
        <span class="nav-icon" style="position:relative; display:inline-block;">
            🛒
            <span class="cart-badge mini-cart-badge-count" id="mobile-cart-count" style="position:absolute; top:-6px; right:-8px; background:#ef4444; color:#fff; font-size:10px; font-weight:bold; border-radius:50%; width:16px; height:16px; display:none; align-items:center; justify-content:center; border:1px solid #fff; padding:0; line-height:16px; min-height:16px;"></span>
        </span>
        <span class="nav-label">Carrinho</span>
    </a>
    <a href="<?= htmlspecialchars($svWhatsappLink, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" aria-label="Falar no WhatsApp">
        <span class="nav-icon">💬</span>
        <span class="nav-label">WhatsApp</span>
    </a>
    <a href="#" onclick="var p=document.getElementById('sv-liz-panel'); if(p){p.classList.add('open'); document.body.classList.add('sv-liz-is-open');} return false;" aria-label="Abrir assistente Liz">
        <span class="nav-icon">🤖</span>
        <span class="nav-label">Liz</span>
    </a>
</div>

<!-- Exit-Intent Recovery Pop-up (Recuperação de Carrinho) -->
<div class="exit-intent-overlay" id="exit-intent-overlay" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.4); backdrop-filter:blur(4px); z-index:100000; align-items:center; justify-content:center;">
    <div class="exit-intent-modal" style="background:#fff; border:1.5px solid #e2e8f0; border-radius:24px; max-width:440px; width:90%; padding:32px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); text-align:center; position:relative; font-family:'Inter',system-ui,-apple-system,sans-serif; animation: popupEntrance 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;">
        <button type="button" id="exit-intent-close" style="position:absolute; top:16px; right:16px; border:0; background:#f1f5f9; width:32px; height:32px; border-radius:50%; font-size:18px; cursor:pointer; color:#64748b; display:flex; align-items:center; justify-content:center; transition:background 0.2s;">&times;</button>
        <div style="font-size:40px; margin-bottom:16px;">🎁</div>
        <h2 style="font-size:22px; font-weight:800; color:#0f172a; margin:0 0 10px; letter-spacing:-0.02em;">Espere! Não vá embora ainda...</h2>
        <p style="font-size:14px; color:#64748b; line-height:1.5; margin:0 0 20px;">Identificamos itens salvos no seu carrinho. Conclua seu pedido nos próximos 15 minutos e aproveite a oferta de recuperação abaixo, conforme elegibilidade do carrinho:</p>
        <div style="background:#f8fafc; border:2px dashed #0b4f88; padding:12px 18px; border-radius:12px; font-weight:800; font-size:18px; color:#0b4f88; letter-spacing:0.05em; display:inline-block; margin-bottom:12px; user-select:all; cursor:pointer;" title="Clique para copiar" id="exit-intent-coupon"><?= htmlspecialchars($svRecoveryCouponCode, ENT_QUOTES, 'UTF-8') ?></div>
        <div style="font-size:12px; color:#475569; margin-bottom:20px; line-height:1.4;"><?= htmlspecialchars($svRecoveryCouponLabel, ENT_QUOTES, 'UTF-8') ?> disponível quando aplicável.</div>
        <div id="exit-intent-timer" style="font-size:12px; color:#ef4444; font-weight:700; margin-bottom:20px;">Oferta expira em: 15:00</div>
        <button type="button" class="btn btn-primary" onclick="if(window.openMiniCart){window.openMiniCart(); document.getElementById('exit-intent-overlay').style.display='none';}else{window.location.href='/carrinho';}" style="width:100%; padding:14px; font-weight:700; border-radius:12px; cursor:pointer;">Ver Meu Carrinho</button>
    </div>
</div>

<!-- Liz Assistant Premium Mascot Widget -->
<link rel="stylesheet" href="/public/assets/liz-assistant/liz-assistant.css?v=7.0">
<script src="/public/assets/liz-assistant/liz-assistant.js"></script>

<script>
// Dark Mode Logic
(function() {
    var toggle = document.getElementById('theme-toggle');
    var currentTheme = localStorage.getItem('sv_theme') || 'light';
    if (currentTheme === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
    
    if (toggle) {
        toggle.addEventListener('click', function() {
            var theme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('sv_theme', theme);
        });
    }
})();
</script>

<a
    class="sv-whatsapp-float"
    href="<?= htmlspecialchars($svWhatsappLink, ENT_QUOTES, 'UTF-8') ?>"
    <?= $svWhatsappDigits !== '' ? 'target="_blank" rel="noopener"' : '' ?>
    aria-label="Falar com a ShopVivaliz no WhatsApp">
    <span class="sv-whatsapp-float__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M12.01 2.01c-5.5 0-9.98 4.47-9.98 9.98 0 1.95.55 3.84 1.58 5.48L2.01 22l4.63-1.57a9.92 9.92 0 0 0 5.37 1.56c5.5 0 9.98-4.48 9.98-9.99 0-5.51-4.48-9.99-9.98-9.99zm5.34 14.36c-.23.64-1.34 1.22-1.85 1.28-.51.06-1.12.18-3.15-.66-2.42-1.01-3.95-3.48-4.07-3.64-.12-.16-.97-1.3-.97-2.48s.62-1.74.84-1.98c.22-.24.47-.29.63-.29s.32.01.46.01c.14 0 .34-.05.53.4.2.47.69 1.68.75 1.8.06.12.1.26.02.41s-.12.24-.24.36c-.12.12-.26.26-.37.36-.12.11-.25.24-.12.47.12.22.56.93 1.2 1.51.83.74 1.54.97 1.77 1.09.23.12.36.1.49-.05.13-.15.56-.65.71-.87.15-.22.3-.18.5-.11.2.07 1.29.61 1.51.72.22.11.37.17.42.26.06.1.06.56-.17 1.2z"/></svg>
    </span>
</a>

<style>
/* CRITICAL LIZ E BUTTON OVERRIDES VIA INLINE STYLE PARA FURAR CACHE CDN */
#sv-liz-panel, #sv-liz-panel.open {
  top: 12px !important;
  bottom: 12px !important;
  height: calc(100dvh - 24px) !important;
  max-height: none !important;
  width: 460px !important;
}

@media(max-width: 600px) {
  #sv-liz-panel, #sv-liz-panel.open {
    top: 10px !important;
    bottom: 10px !important;
    left: 10px !important;
    right: 10px !important;
    width: auto !important;
    height: calc(100dvh - 20px) !important;
  }
}

.btn, .buy-button, .btn-primary, .btn-secondary, button.buy-button {
  padding: 10px 18px !important;
  font-size: 14px !important;
  height: auto !important;
  min-height: 38px !important;
  line-height: 1.2 !important;
}

.card-actions .btn, .card-actions .buy-button, .card-actions button {
  padding: 4px 10px !important;
  font-size: 12px !important;
  min-height: 28px !important;
  height: 32px !important;
  line-height: 32px !important;
  border-radius: 4px !important;
}
</style>

