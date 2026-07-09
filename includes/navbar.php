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
    [
        'label' => 'Políticas',
        'dropdown' => true,
        'match' => ['termos', 'politica-privacidade', 'politica-devolucoes', 'politica-entrega'],
        'items' => [
            ['href' => '/termos.php', 'label' => 'Termos e Condições'],
            ['href' => '/politica-privacidade.php', 'label' => 'Política de Privacidade'],
            ['href' => '/politica-devolucoes.php', 'label' => 'Trocas e Devoluções'],
            ['href' => '/politica-entrega.php', 'label' => 'Política de Entrega'],
        ]
    ],
];

$svLoggedIn = !empty($_SESSION['user_id']);
$svUserName = trim((string)($_SESSION['user_name'] ?? ''));
$svUserFirstName = $svUserName !== '' ? explode(' ', $svUserName)[0] : 'Minha conta';
?>
<nav class="navbar">
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
                <?php if (!empty($link['dropdown'])): ?>
                    <div class="navbar-dropdown">
                        <button class="navbar-dropdown-toggle"<?= $isCurrent ? ' aria-current="page"' : '' ?>>
                            <?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?>
                            <span aria-hidden="true">▼</span>
                        </button>
                        <div class="navbar-dropdown-menu">
                            <?php foreach ($link['items'] as $item): ?>
                                <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="<?= htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>>
                        <?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?>
                        <?php if ($link['href'] === '/carrinho'): ?>
                            <span class="cart-badge" id="nav-cart-count"></span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
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

    // Dropdown menu functionality
    var dropdownToggles = navMenu.querySelectorAll('.navbar-dropdown-toggle');
    dropdownToggles.forEach(function (toggle) {
        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            var dropdown = toggle.closest('.navbar-dropdown');
            var menu = dropdown.querySelector('.navbar-dropdown-menu');
            var isActive = menu.classList.toggle('active');

            // Close other dropdowns
            dropdownToggles.forEach(function (otherToggle) {
                if (otherToggle !== toggle) {
                    var otherMenu = otherToggle.closest('.navbar-dropdown').querySelector('.navbar-dropdown-menu');
                    otherMenu.classList.remove('active');
                }
            });
        });
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.navbar-dropdown')) {
            dropdownToggles.forEach(function (toggle) {
                var menu = toggle.closest('.navbar-dropdown').querySelector('.navbar-dropdown-menu');
                menu.classList.remove('active');
            });
        }
    });
})();
</script>
