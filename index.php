<?php
/**
 * ShopVivaliz - Homepage Pública para Clientes
 * Ecommerce Inteligente com Agentes IA
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/shopvivaliz-version.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: text/html; charset=UTF-8');

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="ShopVivaliz - loja online com catálogo organizado, envio ágil e experiência pensada para conversão.">
    <meta name="theme-color" content="#1F3A70">
    <title>ShopVivaliz | Loja oficial</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-dark: #2d3748;
            --brand-blue: #667eea;
            --brand-navy: #1F3A70;
            --brand-green: #2ECC71;
            --surface: #f7f9fc;
            --line: #e6eaf2;
        }

        body {
            background: linear-gradient(180deg, #ffffff 0%, #f7f9fc 100%);
        }

        .navbar {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(230,234,242,0.9);
        }

        .navbar .container {
            min-height: 84px;
        }

        .brand-lockup {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-lockup img {
            height: 42px;
            width: auto;
        }

        .brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.1;
        }

        .brand-text strong {
            font-size: 18px;
            letter-spacing: 0.08em;
            color: var(--brand-dark);
        }

        .brand-text span {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.12em;
        }

        /* Banner Slider */
        .banner-slider {
            position: relative;
            width: 100%;
            height: 420px;
            background:
                radial-gradient(circle at top left, rgba(102,126,234,0.35), transparent 35%),
                linear-gradient(135deg, #1F3A70 0%, #667eea 52%, #2ECC71 130%);
            border-radius: 0;
            overflow: hidden;
            margin-bottom: 24px;
        }

        .banner-slide {
            display: none;
            width: 100%;
            height: 100%;
            position: absolute;
            top: 0;
            left: 0;
            background: linear-gradient(135deg, rgba(31,58,112,0.88) 0%, rgba(102,126,234,0.92) 55%, rgba(46,204,113,0.88) 130%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 20px;
        }

        .banner-slide.active {
            display: flex;
        }

        .hero-panel {
            max-width: 760px;
            padding: 0 20px;
        }

        .hero-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border: 1px solid rgba(255,255,255,0.28);
            border-radius: 999px;
            background: rgba(255,255,255,0.08);
            font-size: 12px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            margin-bottom: 18px;
        }

        .banner-slide h2 {
            font-size: 46px;
            margin-bottom: 10px;
            line-height: 1.05;
        }

        .banner-slide p {
            font-size: 18px;
            margin-bottom: 28px;
            opacity: 0.96;
        }

        .banner-dots {
            position: absolute;
            bottom: 15px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 8px;
        }

        .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: rgba(255,255,255,0.4);
            cursor: pointer;
            transition: all 0.3s;
        }

        .dot.active {
            background: white;
            width: 30px;
            border-radius: 5px;
        }

        .hero-actions {
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 14px 26px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
            border: none;
            cursor: pointer;
            transition: transform 0.25s ease, box-shadow 0.25s ease, background 0.25s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background: white;
            color: var(--brand-navy);
            box-shadow: 0 10px 28px rgba(17, 24, 39, 0.18);
        }

        .btn-secondary {
            background: transparent;
            color: white;
            border: 1px solid rgba(255,255,255,0.35);
        }

        .trust-strip {
            background: white;
            border-top: 1px solid var(--line);
            border-bottom: 1px solid var(--line);
            padding: 18px 0;
        }

        .trust-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .trust-item {
            display: flex;
            gap: 12px;
            align-items: center;
            padding: 14px 16px;
            border-radius: 16px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid #edf1f7;
        }

        .trust-icon {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: rgba(102,126,234,0.12);
            color: var(--brand-navy);
            font-size: 18px;
            flex-shrink: 0;
        }

        .trust-item strong {
            display: block;
            font-size: 14px;
            color: var(--brand-dark);
        }

        .trust-item span {
            font-size: 12px;
            color: #64748b;
        }

        /* Categorias */
        .categories-section {
            padding: 40px 0;
            background: var(--surface);
        }

        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
        }

        .category-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .category-card:hover {
            border-color: #667eea;
            transform: translateY(-4px);
            box-shadow: 0 4px 12px rgba(102,126,234,0.2);
        }

        .category-icon {
            font-size: 32px;
            margin-bottom: 10px;
        }

        .category-card h3 {
            font-size: 14px;
            color: #1f2937;
            font-weight: 600;
        }

        /* Produtos em Destaque */
        .featured-section {
            padding: 40px 0;
        }

        .section-title {
            font-size: 24px;
            margin-bottom: 30px;
            text-align: center;
            color: #1f2937;
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
        }

        .product-card {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.3s;
        }

        .product-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
        }

        .product-image {
            width: 100%;
            height: 200px;
            background: linear-gradient(135deg, #f0f0f0 0%, #e0e0e0 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 64px;
            color: #999;
        }

        .product-info {
            padding: 16px;
        }

        .product-name {
            font-size: 14px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 8px;
            height: 2.8em;
            overflow: hidden;
        }

        .product-price {
            font-size: 18px;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 12px;
        }

        .product-rating {
            font-size: 12px;
            color: #f59e0b;
            margin-bottom: 12px;
        }

        .product-btn {
            width: 100%;
            padding: 10px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }

        .product-btn:hover {
            background: #764ba2;
        }

        /* Promoção */
        .promo-banner {
            background: linear-gradient(135deg, #1F3A70 0%, #667eea 60%, #2ECC71 140%);
            color: white;
            padding: 40px;
            border-radius: 8px;
            text-align: center;
            margin: 40px 0;
        }

        .promo-banner h2 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .promo-banner p {
            font-size: 16px;
            margin-bottom: 20px;
        }

        /* Newsletter */
        .newsletter-section {
            background: #f9fafb;
            padding: 40px 0;
            margin-top: 40px;
        }

        .newsletter-content {
            max-width: 500px;
            margin: 0 auto;
            text-align: center;
        }

        .newsletter-content h2 {
            font-size: 24px;
            margin-bottom: 10px;
        }

        .newsletter-content p {
            margin-bottom: 20px;
            color: #666;
        }

        .newsletter-form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .newsletter-form input {
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
        }

        .newsletter-form button {
            padding: 12px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .newsletter-form button:hover {
            background: #764ba2;
        }

        /* Busca */
        .search-bar {
            display: flex;
            gap: 8px;
            margin: 20px 0;
        }

        .search-bar input {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
        }

        .search-bar button {
            padding: 12px 24px;
            background: var(--brand-navy);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
        }

        /* Responsivo */
        @media (max-width: 767px) {
            .banner-slider {
                height: 420px;
            }

            .banner-slide h2 {
                font-size: 30px;
            }

            .banner-slide p {
                font-size: 15px;
            }

            .hero-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .trust-grid {
                grid-template-columns: 1fr;
            }

            .categories-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 12px;
            }

            .category-card {
                padding: 12px;
            }

            .category-icon {
                font-size: 24px;
            }

            .products-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .promo-banner {
                padding: 20px;
            }

            .promo-banner h2 {
                font-size: 20px;
            }

            .section-title {
                font-size: 20px;
            }
        }

        @media (min-width: 768px) {
            .banner-slider {
                height: 350px;
            }

            .banner-slide h2 {
                font-size: 32px;
            }

            .categories-grid {
                grid-template-columns: repeat(5, 1fr);
            }

            .products-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .newsletter-form {
                flex-direction: row;
            }

            .newsletter-form input {
                flex: 1;
            }
        }

        @media (min-width: 1025px) {
            .banner-slider {
                height: 400px;
                margin-bottom: 50px;
            }

            .banner-slide h2 {
                font-size: 54px;
            }

            .categories-grid {
                grid-template-columns: repeat(6, 1fr);
            }

            .products-grid {
                grid-template-columns: repeat(5, 1fr);
            }

            .section-title {
                font-size: 28px;
            }
        }
    </style>
</head>
<body>
    <!-- Navegação -->
    <nav class="navbar">
        <div class="container">
            <div class="navbar-brand">
                <a href="/" class="brand-lockup" style="text-decoration: none;">
                    <img src="/images/logo.svg" alt="ShopVivaliz" style="height: 50px; width: auto;">
                    <span class="brand-text">
                        <strong>ShopVivaliz</strong>
                        <span>Loja oficial</span>
                    </span>
                </a>
            </div>
            <button class="menu-toggle" id="menuToggle">☰</button>
            <div class="navbar-menu" id="navMenu">
                <a href="/">Home</a>
                <a href="/catalogo">Catálogo</a>
                <a href="/sobre">Sobre</a>
                <a href="/contato">Contato</a>
                <a href="/carrinho">🛒 Carrinho</a>
            </div>
        </div>
    </nav>

    <section class="trust-strip">
        <div class="container">
            <div class="trust-grid">
                <div class="trust-item">
                    <div class="trust-icon">✓</div>
                    <div>
                        <strong>Marca consistente</strong>
                        <span>Logo e cores unificados em todo o site</span>
                    </div>
                </div>
                <div class="trust-item">
                    <div class="trust-icon">⚡</div>
                    <div>
                        <strong>Compra ágil</strong>
                        <span>Fluxo direto para catálogo e carrinho</span>
                    </div>
                </div>
                <div class="trust-item">
                    <div class="trust-icon">🛡</div>
                    <div>
                        <strong>Base segura</strong>
                        <span>Configuração centralizada e sem segredos expostos</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Banner Slider -->
    <section class="banner-slider">
        <div class="banner-slide active">
            <div class="hero-panel">
                <div class="hero-kicker">Vivaliz · loja oficial</div>
                <h2>Uma vitrine que parece pronta para vender</h2>
                <p>Catálogo organizado, visual limpo e identidade alinhada com o logo da marca.</p>
                <div class="hero-actions">
                    <a href="/catalogo" class="btn btn-primary">Explorar catálogo</a>
                    <a href="/carrinho" class="btn btn-secondary">Ver carrinho</a>
                </div>
            </div>
        </div>
        <div class="banner-slide">
            <div class="hero-panel">
                <div class="hero-kicker">Frete e promoções</div>
                <h2>Promoções com leitura imediata</h2>
                <p>Mensagens curtas, contraste forte e chamada única para não confundir o cliente.</p>
                <div class="hero-actions">
                    <a href="/catalogo" class="btn btn-primary">Ver ofertas</a>
                </div>
            </div>
        </div>
        <div class="banner-slide">
            <div class="hero-panel">
                <div class="hero-kicker">Conversão</div>
                <h2>Compra simples e sem ruído</h2>
                <p>Menos enfeite, mais clareza. A home precisa levar para produto e checkout com confiança.</p>
                <div class="hero-actions">
                    <a href="/catalogo" class="btn btn-primary">Aproveitar</a>
                </div>
            </div>
        </div>
        <div class="banner-dots">
            <span class="dot active" onclick="currentSlide(0)"></span>
            <span class="dot" onclick="currentSlide(1)"></span>
            <span class="dot" onclick="currentSlide(2)"></span>
        </div>
    </section>

    <!-- Busca -->
    <section class="container">
        <div class="search-bar">
            <input type="text" placeholder="Buscar produtos...">
            <button>Buscar</button>
        </div>
    </section>

    <!-- Categorias -->
    <section class="categories-section">
        <div class="container">
            <h2 style="text-align: center; margin-bottom: 30px;">Categorias</h2>
            <div class="categories-grid">
                <div class="category-card">
                    <div class="category-icon">👕</div>
                    <h3>Roupas</h3>
                </div>
                <div class="category-card">
                    <div class="category-icon">👟</div>
                    <h3>Calçados</h3>
                </div>
                <div class="category-card">
                    <div class="category-icon">💎</div>
                    <h3>Acessórios</h3>
                </div>
                <div class="category-card">
                    <div class="category-icon">🏠</div>
                    <h3>Casa</h3>
                </div>
                <div class="category-card">
                    <div class="category-icon">⚽</div>
                    <h3>Esportes</h3>
                </div>
                <div class="category-card">
                    <div class="category-icon">📱</div>
                    <h3>Eletrônicos</h3>
                </div>
            </div>
        </div>
    </section>

    <!-- Produtos em Destaque -->
    <section class="featured-section">
        <div class="container">
            <h2 class="section-title">Produtos em Destaque</h2>
            <div class="products-grid">
                <!-- Produto 1 -->
                <div class="product-card">
                    <div class="product-image">👕</div>
                    <div class="product-info">
                        <div class="product-name">Camiseta Premium Azul</div>
                        <div class="product-price">R$ 49,90</div>
                        <div class="product-rating">★★★★★ (245)</div>
                        <button class="product-btn">Adicionar ao Carrinho</button>
                    </div>
                </div>

                <!-- Produto 2 -->
                <div class="product-card">
                    <div class="product-image">👟</div>
                    <div class="product-info">
                        <div class="product-name">Tênis Conforto</div>
                        <div class="product-price">R$ 189,90</div>
                        <div class="product-rating">★★★★☆ (128)</div>
                        <button class="product-btn">Adicionar ao Carrinho</button>
                    </div>
                </div>

                <!-- Produto 3 -->
                <div class="product-card">
                    <div class="product-image">💎</div>
                    <div class="product-info">
                        <div class="product-name">Relógio Elegante</div>
                        <div class="product-price">R$ 299,90</div>
                        <div class="product-rating">★★★★★ (87)</div>
                        <button class="product-btn">Adicionar ao Carrinho</button>
                    </div>
                </div>

                <!-- Produto 4 -->
                <div class="product-card">
                    <div class="product-image">🧥</div>
                    <div class="product-info">
                        <div class="product-name">Jaqueta Impermeável</div>
                        <div class="product-price">R$ 159,90</div>
                        <div class="product-rating">★★★★★ (156)</div>
                        <button class="product-btn">Adicionar ao Carrinho</button>
                    </div>
                </div>

                <!-- Produto 5 -->
                <div class="product-card">
                    <div class="product-image">🎒</div>
                    <div class="product-info">
                        <div class="product-name">Mochila Travel</div>
                        <div class="product-price">R$ 129,90</div>
                        <div class="product-rating">★★★★☆ (93)</div>
                        <button class="product-btn">Adicionar ao Carrinho</button>
                    </div>
                </div>

                <!-- Produto 6 -->
                <div class="product-card">
                    <div class="product-image">🕶️</div>
                    <div class="product-info">
                        <div class="product-name">Óculos de Sol UV</div>
                        <div class="product-price">R$ 79,90</div>
                        <div class="product-rating">★★★★★ (211)</div>
                        <button class="product-btn">Adicionar ao Carrinho</button>
                    </div>
                </div>

                <!-- Produto 7 -->
                <div class="product-card">
                    <div class="product-image">⌚</div>
                    <div class="product-info">
                        <div class="product-name">Smartwatch Fitness</div>
                        <div class="product-price">R$ 199,90</div>
                        <div class="product-rating">★★★★★ (178)</div>
                        <button class="product-btn">Adicionar ao Carrinho</button>
                    </div>
                </div>

                <!-- Produto 8 -->
                <div class="product-card">
                    <div class="product-image">👜</div>
                    <div class="product-info">
                        <div class="product-name">Bolsa Couro Legítimo</div>
                        <div class="product-price">R$ 249,90</div>
                        <div class="product-rating">★★★★★ (134)</div>
                        <button class="product-btn">Adicionar ao Carrinho</button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Promoção -->
    <section class="promo-banner">
        <div class="container">
            <h2>Aproveite Nossa Promoção Especial</h2>
            <p>Ganhe 20% de desconto na primeira compra com cupom: BEMVINDO20</p>
            <a href="/catalogo" class="btn btn-primary">Usar Cupom</a>
        </div>
    </section>

    <!-- Newsletter -->
    <section class="newsletter-section">
        <div class="container">
            <div class="newsletter-content">
                <h2>Fique por Dentro</h2>
                <p>Receba ofertas exclusivas e novidades do ShopVivaliz</p>
                <form class="newsletter-form">
                    <input type="email" placeholder="Seu e-mail" required>
                    <button type="submit">Se Inscrever</button>
                </form>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 30px; margin-bottom: 30px; text-align: left;">
                <div>
                    <h3 style="margin-bottom: 15px; color: white;">Sobre</h3>
                    <ul style="list-style: none; padding: 0;">
                        <li><a href="/sobre" style="color: #ccc; text-decoration: none;">Sobre Nós</a></li>
                        <li><a href="/carreiras" style="color: #ccc; text-decoration: none;">Carreiras</a></li>
                        <li><a href="/blog" style="color: #ccc; text-decoration: none;">Blog</a></li>
                    </ul>
                </div>
                <div>
                    <h3 style="margin-bottom: 15px; color: white;">Compras</h3>
                    <ul style="list-style: none; padding: 0;">
                        <li><a href="/catalogo" style="color: #ccc; text-decoration: none;">Catálogo</a></li>
                        <li><a href="/promocoes" style="color: #ccc; text-decoration: none;">Promoções</a></li>
                        <li><a href="/rastreamento" style="color: #ccc; text-decoration: none;">Rastreamento</a></li>
                    </ul>
                </div>
                <div>
                    <h3 style="margin-bottom: 15px; color: white;">Suporte</h3>
                    <ul style="list-style: none; padding: 0;">
                        <li><a href="/contato" style="color: #ccc; text-decoration: none;">Contato</a></li>
                        <li><a href="/faq" style="color: #ccc; text-decoration: none;">FAQ</a></li>
                        <li><a href="/politica-privacidade" style="color: #ccc; text-decoration: none;">Privacidade</a></li>
                    </ul>
                </div>
            </div>
            <div style="border-top: 1px solid #444; padding-top: 20px; text-align: center;">
                <p>&copy; 2026 ShopVivaliz. Todos os direitos reservados.</p>
                <p style="font-size: 12px; margin-top: 10px;">Desenvolvido com IA | Entrega Rápida | Compra Segura</p>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
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

        // Banner Slider
        let currentSlideIndex = 0;
        const slides = document.querySelectorAll('.banner-slide');
        const dots = document.querySelectorAll('.dot');

        function showSlide(n) {
            slides.forEach(slide => slide.classList.remove('active'));
            dots.forEach(dot => dot.classList.remove('active'));

            if (n >= slides.length) currentSlideIndex = 0;
            if (n < 0) currentSlideIndex = slides.length - 1;

            slides[currentSlideIndex].classList.add('active');
            dots[currentSlideIndex].classList.add('active');
        }

        function currentSlide(n) {
            currentSlideIndex = n;
            showSlide(currentSlideIndex);
        }

        // Auto-rotate banners
        setInterval(() => {
            currentSlideIndex++;
            showSlide(currentSlideIndex);
        }, 5000);

        // Produtos - adicionar ao carrinho
        document.querySelectorAll('.product-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                alert('Produto adicionado ao carrinho!');
            });
        });

        // Categorias
        document.querySelectorAll('.category-card').forEach(card => {
            card.addEventListener('click', function() {
                alert('Redirecionando para categoria...');
            });
        });
    </script>
</body>
</html>
