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
    header.sv-navbar .navbar-menu { gap: 12px; font-size: 13px; }
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
