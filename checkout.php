<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout | Vivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="container nav-inner">
            <a class="brand-link" href="/">Vivaliz</a>
            <div class="navbar-menu">
                <a href="/catalogo">Catálogo</a>
                <a href="/carrinho.php">Carrinho</a>
            </div>
        </div>
    </nav>

    <main class="container checkout-layout">
        <section class="checkout-panel">
            <h1>Finalizar pedido</h1>
            <p class="muted">Preencha seus dados para registrar o pedido. Pagamento por PIX, boleto ou cartão e cálculo de frete por CEP ficam em confirmação segura.</p>
            <form id="checkout-form" class="checkout-form">
                <label>Nome completo<input name="customer_name" maxlength="120" required autocomplete="name"></label>
                <label>E-mail<input name="customer_email" type="email" maxlength="160" required autocomplete="email"></label>
                <label>Telefone<input name="customer_phone" maxlength="40" required autocomplete="tel"></label>
                <label>CEP<input name="cep" inputmode="numeric" pattern="[0-9]{5}-?[0-9]{3}" maxlength="9" required autocomplete="postal-code" placeholder="00000-000"></label>
                <label>Endereço<input name="address" maxlength="300" required autocomplete="street-address"></label>
                <label>Observações<textarea name="notes" rows="3" maxlength="1000" placeholder="Referência, melhor horário ou observação do pedido"></textarea></label>
                <button class="btn btn-primary" type="submit">Enviar pedido</button>
            </form>
            <div id="checkout-status" class="status-line"></div>
        </section>

        <aside class="checkout-panel">
            <h2>Resumo</h2>
            <div id="cart-items"></div>
            <div class="cart-summary">
                <span>Total estimado</span>
                <strong id="cart-total">Preço sob consulta</strong>
            </div>
            <div class="status-line">
                <strong>Pagamento:</strong> PIX, boleto e cartão
            </div>
            <div class="status-line">
                <strong>Frete:</strong> cálculo por CEP antes da confirmação
            </div>
        </aside>
    </main>

    <script src="/autodev/client.js"></script>
    <script src="/js/cart.js"></script>
</body>
</html>
