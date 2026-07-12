<?php
declare(strict_types=1);

$baseUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'dev.shopvivaliz.com.br');
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="/css/premium-theme.css">
    <style>
        /* Mobile adjustments local to header to avoid breaking global responsive too much */
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--color-text-main);
            font-size: 1.5rem;
            cursor: pointer;
        }

        @media (max-width: 768px) {
            .header-container {
                flex-direction: column;
                gap: 1rem;
            }
            header.premium-header .header-nav {
                display: none;
                flex-direction: column;
                width: 100%;
                gap: 0.5rem;
                text-align: center;
            }
            header.premium-header .header-nav.active {
                display: flex;
            }
            header.premium-header .search-bar {
                width: 100%;
            }
            .header-actions {
                justify-content: space-between;
                width: 100%;
                display: flex;
            }
            .mobile-menu-toggle {
                display: block;
            }
        }
    </style>
</head>
<body>
    <header class="premium-header">
        <div class="header-container" style="max-width: 1200px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
            <a href="/" class="header-logo">
                <span>🏪</span> ShopVivaliz
            </a>

            <nav class="header-nav" id="headerNav" style="display: flex; gap: 1rem; align-items: center;">
                <a href="/" <?php echo $currentPage === 'index.php' || $currentPage === '' ? 'class="active"' : ''; ?>>Início</a>
                <a href="/?cat=ferramentas">Ferramentas</a>
                <a href="/?cat=jardim">Jardim</a>
                <a href="/?cat=cozinha">Cozinha</a>
                <a href="/?cat=banheiro">Banheiro</a>
            </nav>

            <div class="header-actions" style="display: flex; gap: 1rem; align-items: center;">
                <input type="text" class="search-bar" placeholder="🔍 Buscar na Vivaliz..." id="searchBar">
                <a href="/carrinho.php" class="premium-icon-btn" title="Carrinho">
                    🛒
                    <span class="premium-badge" id="cartBadge">0</span>
                </a>
                <a href="/login.php" class="premium-icon-btn" title="Minha Conta">👤</a>
                <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Menu">☰</button>
            </div>
        </div>
    </header>

    <script>
        // Mobile menu toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const headerNav = document.getElementById('headerNav');

        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', function() {
                headerNav.classList.toggle('active');
            });
        }

        // Close mobile menu when clicking on a link
        const navLinks = document.querySelectorAll('.header-nav a');
        navLinks.forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    headerNav.classList.remove('active');
                }
            });
        });

        // Update cart badge (example - should be connected to your cart system)
        function updateCartBadge() {
            const cartBadge = document.getElementById('cartBadge');
            if (cartBadge) {
                // This should be fetched from your cart/session
                const cartCount = localStorage.getItem('cartCount') || '0';
                cartBadge.textContent = cartCount;
            }
        }

        // Call on page load
        updateCartBadge();

        // Search functionality
        const searchBar = document.getElementById('searchBar');
        if (searchBar) {
            searchBar.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    const query = this.value.trim();
                    if (query) {
                        window.location.href = `/?search=${encodeURIComponent(query)}`;
                    }
                }
            });
        }
    </script>
</body>
</html>
