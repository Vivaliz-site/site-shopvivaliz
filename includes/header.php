<?php
declare(strict_types=1);

$baseUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'shopvivaliz.com.br');
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="/css/dazzle-v1.css?v=1.2.0">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        header {
            background: linear-gradient(135deg, #1976d2 0%, #1565c0 100%);
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header-logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            color: white;
            font-size: 1.5rem;
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .header-logo:hover {
            transform: scale(1.05);
        }

        .header-logo-icon {
            font-size: 2rem;
        }

        .header-nav {
            display: flex;
            gap: 2rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .header-nav a {
            color: white;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            font-size: 0.95rem;
            position: relative;
        }

        .header-nav a:hover {
            color: #ffeb3b;
        }

        .header-nav a::after {
            content: '';
            position: absolute;
            bottom: -4px;
            left: 0;
            width: 0;
            height: 2px;
            background: #ffeb3b;
            transition: width 0.3s ease;
        }

        .header-nav a:hover::after {
            width: 100%;
        }

        .header-nav a.active {
            color: #ffeb3b;
            border-bottom: 2px solid #ffeb3b;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .search-bar {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            color: white;
            width: 250px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .search-bar::placeholder {
            color: rgba(255, 255, 255, 0.7);
        }

        .search-bar:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.3);
            box-shadow: 0 0 10px rgba(255, 255, 255, 0.2);
        }

        .cart-icon,
        .user-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: white;
            font-size: 1.2rem;
        }

        .cart-icon:hover,
        .user-icon:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }

        .cart-badge {
            position: absolute;
            background: #ff5722;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: bold;
            top: -5px;
            right: -5px;
        }

        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
        }

        @media (max-width: 768px) {
            .header-container {
                flex-direction: column;
                gap: 1rem;
            }

            .header-nav {
                display: none;
                flex-direction: column;
                width: 100%;
                gap: 1rem;
            }

            .header-nav.active {
                display: flex;
            }

            .search-bar {
                width: 100%;
            }

            .header-actions {
                justify-content: space-between;
                width: 100%;
            }

            .mobile-menu-toggle {
                display: block;
            }

            .header-logo {
                flex: 1;
            }
        }

        .breadcrumb {
            background: #f5f5f5;
            padding: 0.75rem 2rem;
            font-size: 0.9rem;
            color: #666;
            border-bottom: 1px solid #e0e0e0;
        }

        .breadcrumb a {
            color: #1976d2;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .breadcrumb a:hover {
            color: #f57c00;
            text-decoration: underline;
        }

        .breadcrumb-separator {
            margin: 0 0.5rem;
            color: #999;
        }
    </style>
</head>
<body>
    <header>
        <div class="header-container">
            <a href="/" class="header-logo">
                <span class="header-logo-icon">ðŸª</span>
                ShopVivaliz
            </a>

            <nav class="header-nav" id="headerNav">
                <a href="/" <?php echo $currentPage === 'index.php' || $currentPage === '' ? 'class="active"' : ''; ?>>InÃ­cio</a>
                <a href="/?cat=ferramentas">Ferramentas</a>
                <a href="/?cat=jardim">Jardim</a>
                <a href="/?cat=cozinha">Cozinha</a>
                <a href="/?cat=banheiro">Banheiro</a>
                <a href="/admin/visual-editor.php" <?php echo $currentPage === 'visual-editor.php' ? 'class="active"' : ''; ?>>Editor Visual</a>
            </nav>

            <div class="header-actions">
                <input type="text" class="search-bar" placeholder="ðŸ” Buscar produtos..." id="searchBar">
                <a href="/carrinho.php" class="cart-icon" title="Carrinho">
                    ðŸ›’
                    <span class="cart-badge" id="cartBadge">0</span>
                </a>
                <a href="/login.php" class="user-icon" title="Minha Conta">ðŸ‘¤</a>
                <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Menu">â˜°</button>
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

