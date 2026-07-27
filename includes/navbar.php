<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once dirname(__DIR__) . '/includes/site-settings.php';

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
?>
<meta name="theme-color" content="#0b4f88">
<style>
body { opacity: 1 !important; visibility: visible !important; background-color: #f8fafc !important; }
header.sv-navbar {
    background: #0b4f88 !important;
    color: #ffffff !important;
    position: sticky;
    top: 0;
    z-index: 9000;
    box-shadow: 0 4px 20px rgba(11, 79, 136, 0.25) !important;
    padding: 12px 0;
}
header.sv-navbar .nav-inner {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 16px;
}
header.sv-navbar a {
    color: #ffffff !important;
    text-decoration: none;
    font-weight: 700;
    font-size: 14px;
    transition: color 0.2s ease;
}
header.sv-navbar a:hover { color: #35c759 !important; }
header.sv-navbar .navbar-menu > a[aria-current="page"] {
    color: #ffffff !important;
    background: rgba(255,255,255,.16) !important;
    padding: 8px 12px !important;
    border-radius: 999px !important;
}
header.sv-navbar .navbar-menu > a#nav-cart-link {
    color: #ffffff !important;
}
header.sv-navbar a.sv-nav-cta {
    background: #35c759 !important;
    color: #ffffff !important;
    padding: 8px 18px !important;
    border-radius: 999px !important;
    font-weight: 800 !important;
}
header.sv-navbar .navbar-menu {
    display: flex;
    align-items: center;
    gap: 20px;
}
header.sv-navbar .brand-link { min-width: 0; }
header.sv-navbar .brand-logo-img {
    display: block;
    height: 42px;
    width: auto;
    max-width: min(210px, 48vw);
    object-fit: contain;
}
header.sv-navbar .menu-toggle {
    display: none;
    width: 44px;
    height: 44px;
    border: 1px solid rgba(255,255,255,.35);
    border-radius: 10px;
    background: rgba(255,255,255,.12);
    color: #fff;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    line-height: 1;
    cursor: pointer;
    flex: 0 0 auto;
}
.sv-announcement-bar {
    background: linear-gradient(90deg, #07345d, #0b4f88, #07345d);
    color: #ffffff;
    text-align: center;
    padding: 8px 14px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.03em;
    border-bottom: 1px solid rgba(255,255,255,0.15);
    line-height: 1.35;
}
@media (max-width: 768px) {
    header.sv-navbar { padding: 8px 0; }
    header.sv-navbar .nav-inner {
        min-height: 54px;
        flex-wrap: nowrap !important;
        gap: 10px;
        padding-inline: 12px;
    }
    header.sv-navbar .brand-logo-img { height: 36px; max-width: 58vw; }
    header.sv-navbar .menu-toggle { display: inline-flex !important; margin-left: auto; }
    header.sv-navbar .navbar-menu {
        display: none !important;
        position: absolute !important;
        top: calc(100% + 8px) !important;
        right: 10px !important;
        left: auto !important;
        width: min(320px, calc(100vw - 20px)) !important;
        max-width: calc(100vw - 20px) !important;
        max-height: min(68vh, 440px) !important;
        min-height: 0 !important;
        height: auto !important;
        overflow-y: auto !important;
        overscroll-behavior: contain;
        margin: 0 !important;
        padding: 8px !important;
        border: 1px solid #dbe5ef;
        border-radius: 14px;
        background: #ffffff !important;
        box-shadow: 0 18px 45px rgba(15, 23, 42, .28);
        flex-direction: column !important;
        align-items: stretch !important;
        justify-content: flex-start !important;
        gap: 6px !important;
        z-index: 9100;
    }
    header.sv-navbar .navbar-menu.active { display: flex !important; }
    header.sv-navbar .navbar-menu a {
        display: flex !important;
        width: 100% !important;
        min-height: 44px;
        align-items: center !important;
        justify-content: flex-start !important;
        color: #173b63 !important;
        background: #f8fbff !important;
        padding: 10px 12px !important;
        border-radius: 9px !important;
        font-size: 15px !important;
        line-height: 1.25;
    }
    header.sv-navbar .navbar-menu a.sv-nav-cta {
        color: #ffffff !important;
        background: #35c759 !important;
    }
    header.sv-navbar .navbar-menu a[aria-current="page"] { background: #e8eef7 !important; }
    header.sv-navbar .navbar-menu a#nav-cart-link,
    header.sv-navbar .navbar-menu a[aria-current="page"] {
        color: #173b63 !important;
    }
    .sv-announcement-bar { font-size: 11px; padding: 7px 10px; }
}
.sv-skip-link {
    position: absolute !important;
    top: -100px !important;
    left: 20px !important;
    background: #35c759 !important;
    color: #ffffff !important;
    padding: 10px 20px !important;
    font-weight: 800 !important;
    border-radius: 8px !important;
    z-index: 99999 !important;
    transition: top 0.2s ease !important;
    box-shadow: 0 10px 25px rgba(0,0,0,0.2) !important;
}
.sv-skip-link:focus {
    top: 15px !important;
    outline: 3px solid #0b4f88 !important;
}
</style>

<a href="#main-content" class="sv-skip-link">Ir para o conteúdo principal</a>

<?php
$svFreeShippingConfig = sv_free_shipping_config();
?>
<div class="sv-announcement-bar">
    <?php if ($svFreeShippingConfig['enabled'] && $svFreeShippingConfig['threshold'] > 0): ?>
        <span>🚚 FRETE GRÁTIS ACIMA DE R$ <?= number_format($svFreeShippingConfig['threshold'], 2, ',', '.') ?> | 🎁 5% OFF NA 1ª COMPRA COM O CUPOM <strong style="color: #35c759; background: rgba(255,255,255,0.15); padding: 2px 6px; border-radius: 4px;">VOLTEI5</strong></span>
    <?php else: ?>
        <span>🎁 5% OFF NA 1ª COMPRA COM O CUPOM <strong style="color: #35c759; background: rgba(255,255,255,0.15); padding: 2px 6px; border-radius: 4px;">VOLTEI5</strong></span>
    <?php endif; ?>
</div>

<header class="navbar sv-navbar">
    <nav class="container nav-inner" aria-label="Navegação principal">
        <a href="/" class="brand-link" aria-label="Ir para a home da Vivaliz">
            <img src="/images/logo-vivaliz.png" alt="Vivaliz" class="brand-logo-img" width="210" height="46" decoding="async" onerror="this.src='/images/logo-vivaliz-square-v2.png'">
        </a>
        <button type="button" class="menu-toggle" id="svMenuToggle" aria-controls="navMenu" aria-expanded="false" aria-label="Abrir menu">☰</button>
        <div class="navbar-menu" id="navMenu">
            <?php foreach ($svNavLinks as $link): ?>
                <?php $isCurrent = in_array($svNavCurrent, $link['match'], true); ?>
                <a href="<?= htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?><?= $link['href'] === '/catalogo' ? ' class="sv-nav-cta"' : '' ?><?= $link['href'] === '/carrinho' ? ' id="nav-cart-link" class="nav-cart-link"' : '' ?>>
                    <?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endforeach; ?>
            <?php if ($svLoggedIn): ?>
                <a href="/minha-conta/" class="nav-account-link">👤 <?= htmlspecialchars($svUserFirstName, ENT_QUOTES, 'UTF-8') ?></a>
                <a href="/auth/logout.php">Sair</a>
            <?php else: ?>
                <a href="/auth/login.php" class="nav-account-link">Entrar</a>
            <?php endif; ?>
        </div>
    </nav>
</header>
<script>
(function () {
    var toggle = document.getElementById('svMenuToggle');
    var menu = document.getElementById('navMenu');
    if (!toggle || !menu) return;

    function closeMenu() {
        menu.classList.remove('active');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Abrir menu');
        toggle.textContent = '☰';
    }

    closeMenu();

    toggle.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        var open = !menu.classList.contains('active');
        closeMenu();
        if (open) {
            menu.classList.add('active');
            toggle.setAttribute('aria-expanded', 'true');
            toggle.setAttribute('aria-label', 'Fechar menu');
            toggle.textContent = '×';
        }
    });

    menu.addEventListener('click', function (event) {
        if (event.target.closest('a')) closeMenu();
    });

    document.addEventListener('click', function (event) {
        if (menu.classList.contains('active') && !menu.contains(event.target) && !toggle.contains(event.target)) closeMenu();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeMenu();
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth > 768) closeMenu();
    });
})();
</script>
