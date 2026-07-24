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
header.sv-navbar a:hover {
    color: #35c759 !important;
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
header.sv-navbar .brand-logo-img {
    height: 42px;
    width: auto;
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
}
@media (max-width: 768px) {
    header.sv-navbar { padding: 8px 0; }
    header.sv-navbar .nav-inner {
        min-height: 56px;
        flex-wrap: wrap;
        gap: 8px;
    }
    header.sv-navbar .brand-logo-img { height: 36px; max-width: 210px; }
    header.sv-navbar .menu-toggle { display: inline-flex; margin-left: auto; }
    header.sv-navbar .navbar-menu {
        display: none !important;
        width: 100%;
        max-height: min(60vh, 420px);
        overflow-y: auto;
        margin: 4px 0 0;
        padding: 8px;
        border-radius: 12px;
        background: #ffffff;
        box-shadow: 0 14px 32px rgba(15, 23, 42, .22);
        flex-direction: column;
        align-items: stretch;
        gap: 6px;
    }
    header.sv-navbar .navbar-menu.active { display: flex !important; }
    header.sv-navbar .navbar-menu a {
        color: #173b63 !important;
        background: #f8fbff;
        padding: 11px 13px;
        border-radius: 9px;
        font-size: 15px;
    }
    header.sv-navbar .navbar-menu a.sv-nav-cta {
        color: #ffffff !important;
        text-align: center;
    }
    header.sv-navbar .navbar-menu a[aria-current="page"] { background: #e8eef7; }
    .sv-announcement-bar { font-size: 11px; padding: 7px 10px; }
}
</style>

<div class="sv-announcement-bar">
    <span>🚚 FRETE GRÁTIS ACIMA DE R$ 199 | 🎁 5% OFF NA 1ª COMPRA COM O CUPOM <strong style="color: #35c759; background: rgba(255,255,255,0.15); padding: 2px 6px; border-radius: 4px;">VOLTEI5</strong></span>
</div>

<header class="navbar sv-navbar">
    <nav class="container nav-inner" aria-label="Navegação principal">
        <a href="/" class="brand-link" aria-label="Ir para a home da Vivaliz">
            <img src="/images/logo-vivaliz.png" alt="Vivaliz" class="brand-logo-img" width="210" height="46" decoding="async" onerror="this.src='/images/logo-vivaliz-square.png'">
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

    toggle.addEventListener('click', function () {
        var open = menu.classList.toggle('active');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Fechar menu' : 'Abrir menu');
        toggle.textContent = open ? '×' : '☰';
    });

    menu.addEventListener('click', function (event) {
        if (event.target.closest('a')) closeMenu();
    });

    document.addEventListener('click', function (event) {
        if (!menu.classList.contains('active')) return;
        if (!menu.contains(event.target) && !toggle.contains(event.target)) closeMenu();
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth > 768) closeMenu();
    });
})();
</script>