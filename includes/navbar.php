<?php
declare(strict_types=1);

// Paginas publicas nao devem criar PHPSESSID para todo visitante anonimo.
// Retoma a sessao somente quando o cliente ja possui cookie de sessao.
if (session_status() === PHP_SESSION_NONE && isset($_COOKIE[session_name()]) && $_COOKIE[session_name()] !== '') {
    @session_start();
}

require_once dirname(__DIR__) . '/includes/site-settings.php';
require_once dirname(__DIR__) . '/includes/active-coupons.php';

// Ponto comum das paginas publicas: remove hops 301 causados por templates
// legados que ainda imprimem /catalogo, /contato ou /blog sem a barra final.
// O filtro atua somente na resposta HTML de navegacao e nunca altera APIs,
// catalogo persistido, preco ou estoque.
if (empty($GLOBALS['sv_public_canonical_links_filter_registered']) && PHP_SAPI !== 'cli') {
    $svCanonicalMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($svCanonicalMethod, ['GET', 'HEAD'], true)) {
        $GLOBALS['sv_public_canonical_links_filter_registered'] = true;
        ob_start(static function (string $html): string {
            $patterns = [
                '#https://shopvivaliz\.com\.br/catalogo(?=(?:\?|["\']))#' => 'https://shopvivaliz.com.br/catalogo/',
                '#https://shopvivaliz\.com\.br/contato(?=(?:\?|["\']))#' => 'https://shopvivaliz.com.br/contato/',
                '#https://shopvivaliz\.com\.br/blog(?=(?:\?|["\']))#' => 'https://shopvivaliz.com.br/blog/',
                '#(?<=["\'])/catalogo(?=(?:\?|["\']))#' => '/catalogo/',
                '#(?<=["\'])/contato(?=(?:\?|["\']))#' => '/contato/',
                '#(?<=["\'])/blog(?=(?:\?|["\']))#' => '/blog/',
            ];
            foreach ($patterns as $pattern => $replacement) {
                $html = preg_replace($pattern, $replacement, $html) ?? $html;
            }
            return $html;
        });
    }
}

$svNavCurrent = $svNavCurrent ?? trim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
$svNavCurrent = preg_replace('#^index\.php$#', '', $svNavCurrent);
$svNavCurrent = trim((string)$svNavCurrent, '/');

$svNavLinks = [
    ['href' => '/', 'label' => 'Home', 'match' => ['']],
    ['href' => '/catalogo/', 'label' => 'Produtos', 'match' => ['catalogo', 'produtos', 'produto']],
    ['href' => '/sobre/', 'label' => 'Sobre', 'match' => ['sobre']],
    ['href' => '/contato/', 'label' => 'Contato', 'match' => ['contato']],
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
$svWhatsappLink = $svWhatsappDigits !== '' ? "https://wa.me/{$svWhatsappDigits}?text={$svWhatsappMessage}" : '/contato/';

$svFreeShippingConfig = sv_free_shipping_config();
$svPrimaryCoupon = sv_primary_active_coupon();
$svAnnouncementParts = [];
if ($svFreeShippingConfig['enabled'] && $svFreeShippingConfig['threshold'] > 0) {
    $svAnnouncementParts[] = '🚚 FRETE GRÁTIS ACIMA DE R$ ' . number_format((float)$svFreeShippingConfig['threshold'], 2, ',', '.');
}
if (is_array($svPrimaryCoupon)) {
    $svCouponCode = htmlspecialchars((string)($svPrimaryCoupon['code'] ?? ''), ENT_QUOTES, 'UTF-8');
    $svCouponOffer = htmlspecialchars(sv_active_coupon_offer_text($svPrimaryCoupon), ENT_QUOTES, 'UTF-8');
    if ($svCouponCode !== '') {
        $svAnnouncementParts[] = '🎁 ' . $svCouponOffer . ' COM O CUPOM <strong class="sv-announcement-coupon">' . $svCouponCode . '</strong>';
    }
}
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
header.sv-navbar .navbar-menu > a.nav-account-link {
    opacity: .95;
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
header.sv-navbar .brand-link {
    min-width: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 10px 18px;
    border-radius: 22px;
    background: linear-gradient(180deg, rgba(255,255,255,.98), rgba(244,248,252,.98));
    border: 1px solid rgba(11,79,136,.16);
    box-shadow: 0 10px 26px rgba(7,52,93,.16), inset 0 1px 0 rgba(255,255,255,.85);
}
header.sv-navbar .brand-logo-img {
    display: block;
    height: 54px;
    width: auto;
    max-width: min(260px, 52vw);
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
.sv-announcement-coupon {
    color: #35c759;
    background: rgba(255,255,255,0.15);
    padding: 2px 6px;
    border-radius: 4px;
}
@media (max-width: 768px) {
    header.sv-navbar { padding: 8px 0; }
    header.sv-navbar .nav-inner {
        min-height: 54px;
        flex-wrap: nowrap !important;
        gap: 10px;
        padding-inline: 12px;
    }
    header.sv-navbar .brand-link {
        padding: 8px 14px;
        border-radius: 18px;
    }
    header.sv-navbar .brand-logo-img { height: 42px; max-width: 58vw; }
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

<?php if ($svAnnouncementParts !== []): ?>
<div class="sv-announcement-bar">
    <span><?= implode(' | ', $svAnnouncementParts) ?></span>
</div>
<?php endif; ?>

<header class="navbar sv-navbar">
    <nav class="container nav-inner" aria-label="Navegação principal">
        <a href="/" class="brand-link" aria-label="Ir para a home da Vivaliz">
            <img src="/images/logo-vivaliz.png" alt="Vivaliz" class="brand-logo-img" width="210" height="46" decoding="async" onerror="this.src='/images/logo-vivaliz-square-v2.png'">
        </a>
        <button type="button" class="menu-toggle" id="svMenuToggle" aria-controls="navMenu" aria-expanded="false" aria-label="Abrir menu">☰</button>
        <div class="navbar-menu" id="navMenu">
            <?php foreach ($svNavLinks as $link): ?>
                <?php $isCurrent = in_array($svNavCurrent, $link['match'], true); ?>
                <?php
                    $linkClasses = [];
                    if ($link['href'] === '/catalogo/') {
                        $linkClasses[] = 'sv-nav-cta';
                    }
                    if ($link['href'] === '/carrinho') {
                        $linkClasses[] = 'nav-cart-link';
                    }
                    $linkAttrs = '';
                    if ($isCurrent) {
                        $linkAttrs .= ' aria-current="page"';
                    }
                    if ($link['href'] === '/carrinho') {
                        $linkAttrs .= ' id="nav-cart-link"';
                    }
                    if ($linkClasses !== []) {
                        $linkAttrs .= ' class="' . htmlspecialchars(implode(' ', $linkClasses), ENT_QUOTES, 'UTF-8') . '"';
                    }
                ?>
                <a href="<?= htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') ?>"<?= $linkAttrs ?>>
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
<?php require_once __DIR__ . '/liz-assistant-assets.php'; ?>