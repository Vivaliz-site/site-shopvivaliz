<?php
/**
 * Navbar Reutilizável - Inclua em todas as páginas
 * <?php include 'includes/navbar.php'; ?>
 */
?>
<!-- Navegação -->
<nav class="navbar">
    <div class="container">
        <div class="navbar-brand">
            <a href="/" class="brand-lockup" style="text-decoration: none;">
                <img src="/images/logo-vivaliz.png" alt="Vivaliz" style="height: 46px; width: auto;" onerror="this.src='/images/logo.svg'">
            </a>
        </div>
        <button class="menu-toggle" id="menuToggle">☰</button>
        <div class="navbar-menu" id="navMenu">
            <a href="/">Home</a>
            <a href="catalogo">Catálogo</a>
            <a href="/sobre">Sobre</a>
            <a href="/contato">Contato</a>
            <a href="carrinho">Carrinho</a>
        </div>
    </div>
</nav>

<script>
    // Menu toggle mobile
    const menuToggle = document.getElementById('menuToggle');
    const navMenu = document.getElementById('navMenu');

    if (menuToggle) {
        menuToggle.addEventListener('click', function() {
            navMenu.classList.toggle('active');
        });

        navMenu.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', function() {
                navMenu.classList.remove('active');
            });
        });
    }
</script>
