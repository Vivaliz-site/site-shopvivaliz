<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Produto - ShopVivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="container nav-inner">
            <a class="brand-link" href="/">ShopVivaliz</a>
            <div class="navbar-menu">
                <a href="/catalogo">Catálogo</a>
                <a href="/carrinho.php">Carrinho</a>
                <a href="/checkout.php">Checkout</a>
            </div>
        </div>
    </nav>

    <main class="container checkout-layout">
        <section class="checkout-panel">
            <p class="eyebrow">Produto em destaque</p>
            <h1>ShopVivaliz</h1>
            <p class="muted">Página pública de produto com compra direta, cálculo de frete por CEP e checkout assistido.</p>
            <div class="cart-actions">
                <a class="btn btn-primary" href="/catalogo">Comprar agora</a>
                <a class="btn btn-secondary" href="/checkout.php">Ir para checkout</a>
            </div>
        </section>

        <aside class="checkout-panel">
            <h2>Frete por CEP</h2>
            <form action="/checkout.php" method="get" class="checkout-form">
                <label>CEP<input name="cep" inputmode="numeric" pattern="[0-9]{5}-?[0-9]{3}" maxlength="9" required autocomplete="postal-code" placeholder="00000-000"></label>
                <button class="btn btn-primary" type="submit">Calcular frete</button>
            </form>
            <div class="status-line">
                <strong>Pagamento:</strong> PIX, boleto e cartão
            </div>
        </aside>
    </main>
</body>
</html>
