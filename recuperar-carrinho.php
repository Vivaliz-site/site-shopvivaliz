<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Restaurando carrinho | Vivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<main class="container" style="max-width:680px;padding:72px 20px;text-align:center;">
    <h1 style="margin-bottom:12px;">Restaurando seu carrinho</h1>
    <p id="restore-status" style="color:#64748b;line-height:1.6;">Estamos conferindo preço, estoque e disponibilidade atuais antes de carregar os itens.</p>
    <p id="restore-action" hidden><a class="btn btn-primary" href="/catalogo">Ver produtos disponíveis</a></p>
</main>
<script>
(function () {
    var statusEl = document.getElementById('restore-status');
    var actionEl = document.getElementById('restore-action');
    var token = '';
    try {
        var hash = window.location.hash.replace(/^#/, '');
        token = new URLSearchParams(hash).get('token') || '';
        history.replaceState(null, '', window.location.pathname);
    } catch (e) {}

    function fail(message) {
        if (statusEl) statusEl.textContent = message;
        if (actionEl) actionEl.hidden = false;
    }

    if (!/^[A-Za-z0-9_-]{40,64}$/.test(token)) {
        fail('Este link de recuperação é inválido ou já não está disponível.');
        return;
    }

    fetch('/api/checkout/restore-abandonment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        cache: 'no-store',
        credentials: 'same-origin',
        body: JSON.stringify({ token: token })
    })
        .then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                return { ok: response.ok, data: data };
            });
        })
        .then(function (result) {
            if (!result.ok || !result.data.ok || !Array.isArray(result.data.items) || !result.data.items.length) {
                fail('Não foi possível restaurar itens disponíveis deste carrinho. Você pode continuar pelo catálogo atual.');
                return;
            }

            localStorage.setItem('shopvivaliz_cart', JSON.stringify(result.data.items));
            localStorage.setItem('shopvivaliz_cart_updated_at', String(Date.now()));
            localStorage.removeItem('shopvivaliz_shipping_quote');
            window.dispatchEvent(new CustomEvent('shopvivaliz:cart-updated', { detail: { items: result.data.items } }));
            window.location.replace('/carrinho');
        })
        .catch(function () {
            fail('Não foi possível restaurar o carrinho agora. Você pode continuar pelo catálogo atual.');
        });
})();
</script>
</body>
</html>