<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vivaliz - Carrinho</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <link rel="icon" type="image/png" href="/images/logo-vivaliz-square.png">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --navy:#173B63; --green:#2DBB57; --ink:#102033; --line:#DBE5EF; }
        body { font-family:'Manrope','Segoe UI',sans-serif; background:#F8FBFD; color:var(--ink); margin:0; }
        .cart-shell { padding:36px 0 64px; }
        .cart-header { margin-bottom:24px; }
        .cart-header h1 { color:var(--navy); margin-bottom:8px; font-size:28px; }
        .cart-layout { display:grid; grid-template-columns:minmax(0,2fr) minmax(300px,1fr); gap:24px; }
        .cart-panel,.summary-panel { background:white; border:1px solid var(--line); border-radius:18px; box-shadow:0 18px 40px rgba(15,23,42,.06); }
        .cart-panel { overflow:hidden; }
        .cart-table { width:100%; border-collapse:collapse; }
        .cart-table th,.cart-table td { padding:16px; border-bottom:1px solid #eef2f7; text-align:left; vertical-align:middle; }
        .cart-table th { background:#f8fafc; color:#334155; font-size:13px; text-transform:uppercase; letter-spacing:.04em; }
        .item-line { display:flex; align-items:center; gap:14px; }
        .item-thumb { width:72px; height:72px; border-radius:12px; background:#f8fafc; border:1px solid var(--line); object-fit:cover; flex-shrink:0; }
        .item-title { font-weight:600; color:#0f172a; margin-bottom:4px; }
        .item-sku { font-size:12px; color:#64748b; }
        .qty-input { width:84px; padding:10px 12px; border:1px solid #cbd5e1; border-radius:10px; font:inherit; }
        .remove-btn,.primary-btn,.ghost-btn { border:none; border-radius:12px; cursor:pointer; font:inherit; font-weight:600; transition:opacity .2s,background .2s; }
        .remove-btn { background:#fee2e2; color:#b91c1c; padding:10px 12px; }
        .primary-btn { display:inline-flex; align-items:center; justify-content:center; width:100%; background:var(--navy); color:white; padding:14px 18px; text-decoration:none; }
        .primary-btn:hover { opacity:.88; }
        .ghost-btn { display:inline-flex; align-items:center; justify-content:center; width:100%; background:white; color:var(--navy); border:1px solid #cbd5e1; padding:14px 18px; text-decoration:none; }
        .summary-panel { padding:22px; height:fit-content; }
        .summary-panel h2 { color:var(--navy); margin-bottom:16px; }
        .summary-row { display:flex; justify-content:space-between; gap:16px; padding:10px 0; color:#475569; border-bottom:1px solid #eef2f7; }
        .summary-total { font-size:22px; color:#0f172a; font-weight:700; padding-top:18px; border-bottom:none; }
        .shipping-box { margin-top:16px; padding-top:16px; border-top:1px solid #eef2f7; display:grid; gap:10px; }
        .shipping-box label { font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
        .shipping-inline { display:flex; gap:8px; }
        .shipping-input { flex:1; padding:12px 14px; border:1px solid #cbd5e1; border-radius:10px; font:inherit; }
        .shipping-status { font-size:13px; color:#64748b; line-height:1.5; min-height:20px; }
        .summary-actions { display:grid; gap:12px; margin-top:22px; }
        .empty-state { padding:64px 24px; text-align:center; color:#64748b; }
        .empty-state-icon { width:72px; height:72px; margin:0 auto 20px; border-radius:999px; background:#ecfdf5; display:flex; align-items:center; justify-content:center; font-size:32px; }
        .empty-state h2 { color:var(--navy); margin-bottom:10px; font-size:22px; }
        .toast { position:fixed; right:20px; bottom:20px; background:#0f172a; color:white; padding:12px 16px; border-radius:12px; opacity:0; transform:translateY(12px); transition:opacity .2s,transform .2s; z-index:999; }
        .toast.show { opacity:1; transform:translateY(0); }
        @media (max-width:900px) { .cart-layout { grid-template-columns:1fr; } }
        @media (max-width:720px) {
            .cart-table thead { display:none; }
            .cart-table,.cart-table tbody,.cart-table tr,.cart-table td { display:block; width:100%; }
            .cart-table tr { border-bottom:1px solid #eef2f7; }
            .cart-table td { padding:12px 16px; border-bottom:none; }
            .cart-table td[data-label]::before { content:attr(data-label); display:block; font-size:12px; text-transform:uppercase; color:#64748b; margin-bottom:6px; }
        }
    </style>
</head>
<body>
<?php $svNavCurrent = 'carrinho'; include __DIR__ . '/../includes/navbar.php'; ?>
<main class="cart-shell">
    <div class="container">
        <div class="cart-header">
            <h1>Carrinho</h1>
            <p>Revise os itens escolhidos antes de seguir para o checkout.</p>
        </div>
        <div id="cart-root"></div>
    </div>
</main>
<div class="toast" id="cart-toast"></div>
<script>
(function () {
    const root = document.getElementById('cart-root');
    const toast = document.getElementById('cart-toast');
    const initialShippingQuote = readShippingQuote();

    function money(value) {
        const n = Number(value || 0);
        if (!n) return 'Consulte o valor';
        return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function showToast(msg) {
        if (!toast) return;
        toast.textContent = msg;
        toast.classList.add('show');
        clearTimeout(showToast._t);
        showToast._t = setTimeout(function () { toast.classList.remove('show'); }, 2200);
    }

    function readCart() {
        try { const v = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]'); return Array.isArray(v) ? v : []; }
        catch (e) { return []; }
    }

    function writeCart(items) {
        localStorage.setItem('shopvivaliz_cart', JSON.stringify(items));
        clearShippingQuote();
        clearShippingFeedback();
        render();
    }

    function readShippingQuote() {
        try {
            const value = JSON.parse(localStorage.getItem('shopvivaliz_shipping_quote') || 'null');
            return value && typeof value === 'object' ? value : null;
        } catch (error) {
            return null;
        }
    }

    function saveShippingQuote(quote) {
        localStorage.setItem('shopvivaliz_shipping_quote', JSON.stringify(quote || null));
    }

    function clearShippingQuote() {
        localStorage.removeItem('shopvivaliz_shipping_quote');
    }

    function readShippingFeedback() {
        try {
            return String(localStorage.getItem('shopvivaliz_shipping_feedback') || '');
        } catch (error) {
            return '';
        }
    }

    function saveShippingFeedback(message) {
        localStorage.setItem('shopvivaliz_shipping_feedback', String(message || ''));
    }

    function clearShippingFeedback() {
        localStorage.removeItem('shopvivaliz_shipping_feedback');
    }

    function formatCep(value) {
        const digits = String(value || '').replace(/\D/g, '').slice(0, 8);
        return digits.length > 5 ? digits.slice(0, 5) + '-' + digits.slice(5) : digits;
    }

    function render() {
        const items = readCart();
        if (!items.length) {
            root.innerHTML = '<section class="cart-panel empty-state"><div class="empty-state-icon">🛒</div><h2>Seu carrinho está vazio</h2><p>Adicione produtos no catálogo para continuar a compra.</p><div class="summary-actions" style="max-width:320px;margin:24px auto 0;"><a class="primary-btn" href="/catalogo">Ir para o catálogo</a></div></section>';
            return;
        }
        const subtotal = items.reduce(function (s, i) { return s + Number(i.price || 0) * Number(i.quantity || 1); }, 0);
        const shippingQuote = readShippingQuote();
        const shippingFeedback = readShippingFeedback();
        const shippingTotal = Number(shippingQuote && shippingQuote.shipping_total || 0);
        const shippingLabel = shippingQuote && shippingQuote.selected_option
            ? [shippingQuote.selected_option.company, shippingQuote.selected_option.name].filter(Boolean).join(' - ')
            : '';
        root.innerHTML = '<div class="cart-layout"><section class="cart-panel"><table class="cart-table"><thead><tr><th>Produto</th><th>Preço</th><th>Qtd</th><th>Subtotal</th><th></th></tr></thead><tbody>' +
            items.map(function (item, idx) {
                const img = item.image_url || item.image || '/images/logo-vivaliz-square.png';
                const qty = Math.max(1, Number(item.quantity || 1));
                return '<tr><td data-label="Produto"><div class="item-line"><img class="item-thumb" src="' + img + '" alt="' + (item.name || 'Produto') + '" onerror="this.src=\'/images/logo-vivaliz-square.png\'"><div><div class="item-title">' + (item.name || 'Produto Vivaliz') + '</div><div class="item-sku">' + (item.sku || '') + '</div></div></div></td><td data-label="Preço">' + money(item.price) + '</td><td data-label="Qtd"><input class="qty-input" type="number" min="1" value="' + qty + '" data-index="' + idx + '" data-role="quantity"></td><td data-label="Subtotal">' + money(Number(item.price || 0) * qty) + '</td><td><button class="remove-btn" data-index="' + idx + '" data-role="remove">✕</button></td></tr>';
            }).join('') +
            '</tbody></table></section><aside class="summary-panel"><h2>Resumo</h2><div class="summary-row"><span>Itens</span><strong>' + items.reduce(function (s, i) { return s + Number(i.quantity || 1); }, 0) + '</strong></div><div class="summary-row"><span>Subtotal</span><strong>' + money(subtotal) + '</strong></div><div class="summary-row"><span>Frete</span><strong>' + (shippingTotal > 0 ? money(shippingTotal) : 'A calcular') + '</strong></div><div class="summary-row summary-total"><span>Total</span><span>' + money(subtotal + shippingTotal) + '</span></div><div class="shipping-box"><label for="shipping-cep">Calcular frete</label><div class="shipping-inline"><input class="shipping-input" id="shipping-cep" type="text" inputmode="numeric" maxlength="9" placeholder="Digite o CEP" value="' + formatCep(shippingQuote && shippingQuote.cep || '') + '"><button class="ghost-btn" type="button" id="shipping-calc">Calcular</button></div><div class="shipping-status" id="shipping-status">' + (shippingLabel ? 'Opcao atual: ' + shippingLabel : (shippingFeedback || 'Informe o CEP para calcular o frete.')) + '</div></div><div class="summary-actions"><a class="primary-btn" href="/checkout">Ir para o checkout</a><a class="ghost-btn" href="/catalogo">Continuar comprando</a></div></aside></div>';

        root.querySelectorAll('[data-role="quantity"]').forEach(function (input) {
            input.addEventListener('change', function () {
                const next = readCart();
                const idx = Number(input.dataset.index);
                if (!next[idx]) return;
                next[idx].quantity = Math.max(1, Number(input.value || 1));
                writeCart(next);
                showToast('Quantidade atualizada.');
            });
        });
        root.querySelectorAll('[data-role="remove"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const next = readCart();
                const idx = Number(btn.dataset.index);
                if (!next[idx]) return;
                next.splice(idx, 1);
                writeCart(next);
                showToast('Item removido.');
            });
        });

        const shippingCep = document.getElementById('shipping-cep');
        const shippingCalc = document.getElementById('shipping-calc');
        const shippingStatus = document.getElementById('shipping-status');
        if (shippingCep) {
            shippingCep.addEventListener('input', function () {
                shippingCep.value = formatCep(shippingCep.value);
            });
        }
        if (shippingCalc && shippingCep) {
            shippingCalc.addEventListener('click', function () {
                const cep = String(shippingCep.value || '').replace(/\D/g, '');
                if (cep.length !== 8) {
                    if (shippingStatus) shippingStatus.textContent = 'Informe um CEP valido com 8 digitos.';
                    return;
                }

                shippingCalc.setAttribute('disabled', 'disabled');
                shippingCalc.textContent = 'Calculando...';
                if (shippingStatus) shippingStatus.textContent = 'Consultando frete...';

                fetch('/api/melhorenvio/shipping-check.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        cep: cep,
                        items: items.map(function (item) {
                            return {
                                sku: item.sku || '',
                                product_id: item.id || '',
                                olist_product_id: item.olist_product_id || '',
                                quantity: item.quantity || 1,
                                price: Number(item.price || 0)
                            };
                        })
                    })
                })
                .then(function (response) { return response.json().then(function (data) { return { ok: response.ok, data: data }; }); })
                .then(function (result) {
                    shippingCalc.removeAttribute('disabled');
                    shippingCalc.textContent = 'Calcular';

                    if (!result.ok || !result.data || !result.data.ok || !result.data.selected_option) {
                        clearShippingQuote();
                        saveShippingFeedback(result.data && (result.data.message || result.data.error) ? String(result.data.message || result.data.error) : 'Nao foi possivel calcular o frete agora.');
                        render();
                        return;
                    }

                    saveShippingQuote({
                        cep: cep,
                        shipping_total: Number(result.data.shipping_total || 0),
                        selected_option: result.data.selected_option || null,
                        provider: result.data.provider || 'melhorenvio'
                    });
                    clearShippingFeedback();
                    render();
                    showToast('Frete atualizado.');
                })
                .catch(function () {
                    shippingCalc.removeAttribute('disabled');
                    shippingCalc.textContent = 'Calcular';
                    saveShippingFeedback('Erro ao consultar o frete.');
                    render();
                });
            });
        }
    }

    render();
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
