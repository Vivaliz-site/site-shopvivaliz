<?php
declare(strict_types=1);

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
?>
<header class="site-header">
    <div class="header-container">
        <a href="/" class="header-logo" aria-label="ShopVivaliz - página inicial">
            <span class="header-logo-icon" aria-hidden="true">🏪</span>
            <span>ShopVivaliz</span>
        </a>

        <button class="mobile-menu-toggle" id="mobileMenuToggle" type="button" aria-label="Abrir menu" aria-controls="headerNav" aria-expanded="false">☰</button>

<?php
require_once __DIR__ . '/catalog-runtime.php';
$svHeaderCats = [];
if (function_exists('svcr_products')) {
    $allProds = svcr_products();
    $catCounts = [];
    foreach ($allProds as $p) {
        $c = trim((string)($p['category'] ?? ''));
        if ($c !== '' && ($p['stock'] ?? 0) > 0 && ($p['price'] ?? 0) > 0) {
            $catCounts[$c] = ($catCounts[$c] ?? 0) + 1;
        }
    }
    arsort($catCounts);
    $svHeaderCats = array_slice(array_keys($catCounts), 0, 4);
}
if ($svHeaderCats === []) {
    $svHeaderCats = ['Rodízios', 'Vasos Decorativos', 'Ferramentas Manuais', 'Banheiro'];
}
?>
        <nav class="header-nav" id="headerNav" aria-label="Navegação principal">
            <a href="/"<?= in_array($currentPage, ['', 'index.php', 'home.php'], true) ? ' class="active" aria-current="page"' : '' ?>>Início</a>
            <?php foreach ($svHeaderCats as $catName): ?>
                <a href="/catalogo?categoria=<?= urlencode($catName) ?>"><?= htmlspecialchars($catName, ENT_QUOTES, 'UTF-8') ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="header-actions">
            <label class="sr-only" for="searchBar">Buscar produtos</label>
            <input type="search" class="search-bar" placeholder="Buscar produtos..." id="searchBar" autocomplete="off">
            <a href="/carrinho" class="cart-icon" title="Carrinho" aria-label="Abrir carrinho">
                <span aria-hidden="true">🛒</span>
                <span class="cart-badge" id="cartBadge">0</span>
            </a>
            <a href="/login" class="user-icon" title="Minha conta" aria-label="Minha conta"><span aria-hidden="true">👤</span></a>
        </div>
    </div>
</header>

<script>
(function () {
    const toggle = document.getElementById('mobileMenuToggle');
    const nav = document.getElementById('headerNav');
    const search = document.getElementById('searchBar');
    const badge = document.getElementById('cartBadge');

    if (toggle && nav) {
        toggle.addEventListener('click', function () {
            const open = nav.classList.toggle('active');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        nav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                nav.classList.remove('active');
                toggle.setAttribute('aria-expanded', 'false');
            });
        });
    }

    if (badge) {
        const count = Number.parseInt(localStorage.getItem('cartCount') || '0', 10);
        badge.textContent = Number.isFinite(count) && count > 0 ? String(count) : '0';
    }

    if (search) {
        search.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') return;
            const query = search.value.trim();
            if (query !== '') window.location.href = '/catalogo?busca=' + encodeURIComponent(query);
        });
    }
})();
</script>
