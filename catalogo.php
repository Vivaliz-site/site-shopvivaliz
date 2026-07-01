<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo - ShopVivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="container nav-inner">
            <a class="brand-link" href="/">ShopVivaliz</a>
            <div class="navbar-menu">
                <a href="/">Home</a>
                <a href="/catalogo.php" aria-current="page">Catálogo</a>
                <a href="/admin/">Admin</a>
            </div>
        </div>
    </nav>

    <main class="catalog-page">
        <section class="catalog-header">
            <div class="container catalog-header-inner">
                <div>
                    <p class="eyebrow">Catálogo ao vivo</p>
                    <h1>Produtos prontos para venda</h1>
                    <p class="muted">Itens importados do Tiny/Olist com imagens vinculadas ao catálogo do site.</p>
                </div>
                <form class="catalog-search" role="search">
                    <input id="catalog-search" type="search" placeholder="Buscar por SKU ou produto" autocomplete="off">
                    <button type="submit">Buscar</button>
                </form>
            </div>
        </section>

        <section class="container catalog-tools">
            <form class="cep-checker" action="/checkout.php" method="get">
                <label for="catalog-cep">CEP para calcular frete</label>
                <div class="cep-checker-row">
                    <input id="catalog-cep" name="cep" type="text" inputmode="numeric" placeholder="Digite seu CEP" maxlength="9">
                    <button type="submit">Calcular frete</button>
                </div>
            </form>
            <div id="catalog-status" class="status-line">Carregando catálogo...</div>
        </section>

        <section class="container product-grid" id="product-grid" aria-live="polite"></section>
    </main>

    <script src="/autodev/client.js"></script>
    <script src="/js/catalog.js"></script>
</body>
</html>
