<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Catálogo de produtos ShopVivaliz — encontre tudo com entrega rápida.">
    <title>Catálogo — ShopVivaliz</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <style>
        :root { --green:#22c55e; --navy:#1e3a5f; --dark:#1a3050; }

        /* ── CATÁLOGO ────────────────────────────────────── */
        .catalog-page { padding-bottom: 60px; }

        .catalog-header {
            background: linear-gradient(135deg, var(--navy), #2563eb);
            color: white; padding: 40px 0;
        }
        .catalog-header-inner {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 20px;
        }
        .eyebrow { font-size: 12px; text-transform: uppercase; letter-spacing: .1em; opacity: .75; margin-bottom: 6px; }
        .catalog-header h1 { font-size: 28px; font-weight: 700; margin-bottom: 6px; }
        .muted { font-size: 14px; opacity: .8; }

        .catalog-search {
            display: flex; gap: 8px; flex-shrink: 0;
            width: 100%; max-width: 360px;
        }
        .catalog-search input {
            flex: 1; padding: 11px 14px; border: none; border-radius: 6px;
            font-size: 14px; outline: none;
        }
        .catalog-search button {
            padding: 11px 20px; background: var(--green); color: white;
            border: none; border-radius: 6px; font-weight: 600; cursor: pointer;
            white-space: nowrap; transition: background .2s;
        }
        .catalog-search button:hover { background: #16a34a; }

        .catalog-tools {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 12px; padding-top: 20px; padding-bottom: 8px;
        }
        .status-line { font-size: 13px; color: #6b7280; }
        .products-count-label { font-size: 13px; color: #6b7280; }

        .cep-checker { display: flex; flex-direction: column; gap: 6px; }
        .cep-checker label { font-size: 12px; color: #6b7280; font-weight: 500; }
        .cep-checker-row { display: flex; gap: 8px; }
        .cep-checker-row input {
            padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px;
            font-size: 13px; width: 160px;
        }
        .cep-checker-row button {
            padding: 8px 14px; background: var(--navy); color: white;
            border: none; border-radius: 6px; font-size: 13px; cursor: pointer;
        }

        /* ── GRID DE PRODUTOS ───────────────────────────── */
        .product-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-top: 24px;
        }
        @media (min-width: 480px)  { .product-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (min-width: 768px)  { .product-grid { grid-template-columns: repeat(4, 1fr); } }
        @media (min-width: 1024px) { .product-grid { grid-template-columns: repeat(5, 1fr); } }

        .product-card {
            background: white; border-radius: 10px; overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,.08); transition: all .25s;
        }
        .product-card:hover { transform: translateY(-4px); box-shadow: 0 8px 20px rgba(0,0,0,.12); }

        .product-image {
            display: block; width: 100%; height: 180px; overflow: hidden;
            background: #f3f4f6;
        }
        .product-image img {
            width: 100%; height: 100%; object-fit: cover; transition: transform .3s;
        }
        .product-card:hover .product-image img { transform: scale(1.05); }

        .product-info { padding: 12px; }
        .product-sku { font-size: 10px; color: #9ca3af; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 4px; }
        .product-info h2 {
            font-size: 13px; font-weight: 600; color: #1f2937; margin-bottom: 8px;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        }
        .product-meta {
            display: flex; align-items: center; justify-content: space-between;
            font-size: 12px; color: #6b7280; margin-bottom: 10px;
        }
        .product-meta span:first-child { font-size: 15px; font-weight: 700; color: var(--navy); }

        .buy-button {
            width: 100%; padding: 9px; background: var(--green); color: white;
            border: none; border-radius: 6px; font-weight: 600; font-size: 13px;
            cursor: pointer; transition: background .2s;
        }
        .buy-button:hover { background: #16a34a; }

        /* ── MENU TOGGLE (mobile) ───────────────────────── */
        .menu-toggle {
            display: none; background: none; border: none; font-size: 22px;
            cursor: pointer; padding: 4px 8px; color: #1f2937;
        }
        @media (max-width: 767px) {
            .menu-toggle { display: block; }
            .navbar-menu {
                display: none; flex-direction: column; position: absolute;
                top: 60px; left: 0; right: 0; background: white;
                border-bottom: 1px solid #e5e7eb; padding: 12px 16px; z-index: 200;
            }
            .navbar-menu.active { display: flex; }
        }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
    <div class="container">
        <div class="navbar-brand">
            <a href="/" style="display:flex;align-items:center;text-decoration:none;">
                <img src="/images/logo.svg" alt="ShopVivaliz" style="height:44px;width:auto;"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                <span style="display:none;font-size:20px;font-weight:700;color:#1e3a5f;">ShopVivaliz</span>
            </a>
        </div>
        <button class="menu-toggle" id="menuToggle" aria-label="Menu">☰</button>
        <div class="navbar-menu" id="navMenu">
            <a href="/">Home</a>
            <a href="/catalogo" aria-current="page" style="color:#22c55e;font-weight:600;">Catálogo</a>
            <a href="/sobre">Sobre</a>
            <a href="/contato">Contato</a>
            <a href="/carrinho">🛒 Carrinho</a>
        </div>
    </div>
</nav>

<main class="catalog-page">
    <!-- Cabeçalho do catálogo -->
    <section class="catalog-header">
        <div class="container catalog-header-inner">
            <div>
                <p class="eyebrow">Catálogo ao vivo</p>
                <h1>Produtos prontos para venda</h1>
                <p class="muted">Encontre o produto ideal com entrega rápida e segura.</p>
            </div>
            <form class="catalog-search" role="search">
                <input id="catalog-search" type="search" placeholder="Buscar por nome ou SKU" autocomplete="off">
                <button type="submit">Buscar</button>
            </form>
        </div>
    </section>

    <!-- Ferramentas -->
    <section class="container catalog-tools">
        <div id="catalog-status" class="status-line">Carregando catálogo...</div>
        <div class="products-count-label">
            <span id="products-count">0</span> produtos
        </div>
    </section>

    <!-- Grade de produtos -->
    <section class="container product-grid" id="product-grid" aria-live="polite"></section>
</main>

<!-- Footer -->
<footer>
    <div class="container">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:24px;margin-bottom:24px;text-align:left;">
            <div>
                <h3 style="margin-bottom:10px;font-size:14px;">Compras</h3>
                <ul style="list-style:none;padding:0;display:flex;flex-direction:column;gap:7px;">
                    <li><a href="/"        style="color:#ccc;text-decoration:none;font-size:13px;">Home</a></li>
                    <li><a href="/catalogo" style="color:#ccc;text-decoration:none;font-size:13px;">Catálogo</a></li>
                    <li><a href="/carrinho" style="color:#ccc;text-decoration:none;font-size:13px;">Carrinho</a></li>
                </ul>
            </div>
            <div>
                <h3 style="margin-bottom:10px;font-size:14px;">Suporte</h3>
                <ul style="list-style:none;padding:0;display:flex;flex-direction:column;gap:7px;">
                    <li><a href="/contato"              style="color:#ccc;text-decoration:none;font-size:13px;">Contato</a></li>
                    <li><a href="/faq"                  style="color:#ccc;text-decoration:none;font-size:13px;">FAQ</a></li>
                    <li><a href="/politica-privacidade" style="color:#ccc;text-decoration:none;font-size:13px;">Privacidade</a></li>
                </ul>
            </div>
        </div>
        <div style="border-top:1px solid #444;padding-top:16px;text-align:center;">
            <p style="font-size:13px;">&copy; 2026 ShopVivaliz. Todos os direitos reservados.</p>
        </div>
    </div>
</footer>

<script>
    const toggle = document.getElementById('menuToggle');
    const nav    = document.getElementById('navMenu');
    if (toggle && nav) {
        toggle.addEventListener('click', () => nav.classList.toggle('active'));
        nav.querySelectorAll('a').forEach(a => a.addEventListener('click', () => nav.classList.remove('active')));
    }
</script>
<script src="/js/catalog.js"></script>
</body>
</html>
