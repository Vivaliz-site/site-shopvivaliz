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
?>
<link rel="stylesheet" href="/css/shopvivaliz-visual-v3.css?v=3.0.0">
<nav class="navbar sv-navbar">
    <div class="container nav-inner">
        <a href="/" class="brand-link" aria-label="Ir para a home da Vivaliz">
            <img src="/images/logo-vivaliz.png" alt="Vivaliz" class="brand-logo-img" onerror="this.src='/images/logo-vivaliz-square.png'">
        </a>
        <button class="menu-toggle" id="menuToggle" type="button" aria-expanded="false" aria-controls="navMenu" aria-label="Abrir menu">
            <span aria-hidden="true">☰</span>
        </button>
        <div class="navbar-menu" id="navMenu">
            <?php foreach ($svNavLinks as $link): ?>
                <?php $isCurrent = in_array($svNavCurrent, $link['match'], true); ?>
                <a href="<?= htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?><?= $link['href'] === '/catalogo' ? ' class="sv-nav-cta"' : '' ?>>
                    <?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($link['href'] === '/carrinho'): ?>
                        <span class="cart-badge" id="nav-cart-count"></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
            <?php if ($svLoggedIn): ?>
                <a href="/meus-pedidos.php" class="nav-account-link"<?= $svNavCurrent === 'meus-pedidos.php' ? ' aria-current="page"' : '' ?>>👤 <?= htmlspecialchars($svUserFirstName, ENT_QUOTES, 'UTF-8') ?></a>
                <a href="/auth/logout.php">Sair</a>
            <?php else: ?>
                <a href="/auth/login.php" class="nav-account-link">Entrar</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="sv-liz-assistant" data-endpoint="/api/agent/squad-chat.php">
    <button class="sv-liz-trigger" type="button" aria-expanded="false" aria-controls="svLizPanel">
        <img src="/images/logo-vivaliz-square.png" alt="Assistente Liz">
        <span class="sv-liz-trigger-copy"><strong>Fale com a Liz</strong><small>Ajuda para encontrar produtos</small></span>
    </button>
    <section class="sv-liz-panel" id="svLizPanel" hidden aria-label="Assistente virtual Liz">
        <header>
            <img src="/images/logo-vivaliz-square.png" alt="">
            <div><strong>Liz</strong><span>Assistente virtual ShopVivaliz</span></div>
            <button class="sv-liz-close" type="button" aria-label="Fechar">×</button>
        </header>
        <div class="sv-liz-messages" aria-live="polite">
            <div class="sv-liz-message is-liz">Olá! Posso ajudar você a encontrar um produto, comparar opções ou tirar dúvidas sobre a loja.</div>
        </div>
        <form class="sv-liz-form">
            <label class="sr-only" for="svLizInput">Digite sua mensagem</label>
            <input id="svLizInput" type="text" maxlength="500" placeholder="Como posso ajudar?" autocomplete="off">
            <button type="submit">Enviar</button>
        </form>
    </section>
</div>

<script>
(function () {
    var menuToggle = document.getElementById('menuToggle');
    var navMenu = document.getElementById('navMenu');
    if (!menuToggle || !navMenu) return;
    menuToggle.addEventListener('click', function () {
        var isOpen = navMenu.classList.toggle('active');
        menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
    navMenu.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
            navMenu.classList.remove('active');
            menuToggle.setAttribute('aria-expanded', 'false');
        });
    });
})();
</script>
<script src="/js/shopvivaliz-visual-v3.js?v=3.0.0" defer></script>
