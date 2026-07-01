<?php
// Shop Vivaliz - Homepage com logo integrado
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VIVALIZ - Loja Online</title>
    <link rel="icon" type="image/svg+xml" href="/assets/images/logo/vivaliz-logo.svg">
    <link rel="stylesheet" href="/assets/css/header.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f9fafb; color: #333; }
        .container { max-width: 1280px; margin: 0 auto; padding: 2rem 1rem; }
        .hero { background: linear-gradient(135deg, #1e3a5f 0%, #22c55e 100%); color: white; padding: 4rem 1rem; text-align: center; border-radius: 12px; margin-top: 2rem; }
        .hero h1 { font-size: 2.5rem; margin-bottom: 1rem; }
        .hero p { font-size: 1.2rem; margin-bottom: 2rem; }
        .btn { display: inline-block; background: white; color: #1e3a5f; padding: 0.75rem 2rem; border-radius: 6px; text-decoration: none; font-weight: bold; transition: transform 0.2s; }
        .btn:hover { transform: scale(1.05); }
        .products { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 2rem; margin-top: 2rem; }
        .product-card { background: white; border-radius: 12px; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .product-card:hover { box-shadow: 0 8px 16px rgba(0,0,0,0.15); transform: translateY(-2px); transition: all 0.2s; }
        .cart-button { background: #22c55e; color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .cart-button:hover { background: #16a34a; }
    </style>
</head>
<body>
    <header>
        <div class="navbar-container">
            <a href="/" class="navbar-brand">
                <svg viewBox="0 0 400 200" xmlns="http://www.w3.org/2000/svg" style="height: 50px; width: auto;">
                    <circle cx="70" cy="70" r="50" fill="#22c55e"/>
                    <path d="M 50 70 L 60 85 L 90 55" stroke="white" stroke-width="6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M 90 140 L 140 90 L 180 140" stroke="#1e3a5f" stroke-width="20" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    <text x="210" y="110" font-family="Arial, sans-serif" font-size="72" font-weight="bold" fill="#1e3a5f" letter-spacing="2">VIVALIZ</text>
                </svg>
            </a>
            <nav>
                <a href="#" style="margin-left: 2rem; color: #333; text-decoration: none; font-weight: 500;">Produtos</a>
                <a href="#" style="margin-left: 2rem; color: #333; text-decoration: none; font-weight: 500;">Sobre</a>
                <a href="#" style="margin-left: 2rem; color: #333; text-decoration: none; font-weight: 500;">Contato</a>
            </nav>
        </div>
    </header>

    <div class="container">
        <div class="hero">
            <h1>Bem-vindo à VIVALIZ</h1>
            <p>Qualidade garantida e melhor preço para você</p>
            <a href="#products" class="btn">Ver Produtos</a>
        </div>

        <section id="products" class="products">
            <div class="product-card">
                <h3>Produto Premium</h3>
                <p>Descrição do produto com qualidade VIVALIZ</p>
                <p style="color: #22c55e; font-size: 1.5rem; margin-top: 1rem; font-weight: bold;">R$ 99,90</p>
                <button class="cart-button">Adicionar ao Carrinho</button>
            </div>
            <div class="product-card">
                <h3>Produto Exclusivo</h3>
                <p>Oferta especial apenas para você</p>
                <p style="color: #22c55e; font-size: 1.5rem; margin-top: 1rem; font-weight: bold;">R$ 149,90</p>
                <button class="cart-button">Adicionar ao Carrinho</button>
            </div>
            <div class="product-card">
                <h3>Produto Popular</h3>
                <p>Mais vendido pelos clientes VIVALIZ</p>
                <p style="color: #22c55e; font-size: 1.5rem; margin-top: 1rem; font-weight: bold;">R$ 79,90</p>
                <button class="cart-button">Adicionar ao Carrinho</button>
            </div>
        </section>
    </div>

    <footer style="background: #1e3a5f; color: white; text-align: center; padding: 2rem; margin-top: 4rem;">
        <p>&copy; 2026 VIVALIZ. Todos os direitos reservados. | CNPJ: 49.903.300/0001-70</p>
    </footer>
</body>
</html>