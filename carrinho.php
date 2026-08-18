<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carrinho | Vivaliz</title>
    <link rel="icon" href="/images/favicon.svg?v=2026-07-27" type="image/svg+xml">
    <link rel="icon" href="/favicon.png?v=2026-07-27" type="image/png">
    <link rel="alternate icon" href="/favicon.ico?v=2" type="image/x-icon">
    <link rel="apple-touch-icon" href="/favicon.png?v=2">
    <meta name="msapplication-TileColor" content="#173B63">
    <meta name="theme-color" content="#173B63">
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/shipping-v7.css">
    <link rel="stylesheet" href="/css/first-purchase-popup-v1.css?v=2026-07-30-1">
    <link rel="stylesheet" href="/css/layout-polish-v1.css?v=2026-07-29-1">
    <style>
        .cart-page { padding: 36px 0 64px; }
        .cart-layout { display: grid; grid-template-columns: 1fr 320px; gap: 24px; align-items: start; }
        .cart-card { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 28px; box-shadow: var(--shadow); }
        .cart-title { margin: 0 0 20px; font-size: 22px; font-weight: 800; }
        .cart-empty { text-align: center; padding: 48px 0; }
        .cart-empty p { color: var(--muted); margin: 8px 0 24px; }
        .cart-item { display: grid; grid-template-columns: 72px 1fr auto; gap: 14px; align-items: center; padding: 14px 0; border-bottom: 1px solid var(--line); }
        .cart-item:last-child { border-bottom: none; }
        .cart-item img { width: 72px; height: 72px; object-fit: contain; border-radius: 8px; background: #f3f5fa; border: 1px solid var(--line); }
        .cart-item-info strong { display: block; font-size: 14px; line-height: 1.35; }
        .cart-item-info span { color: var(--muted); font-size: 13px; }
        .cart-item-price { font-weight: 800; font-size: 15px; white-space: nowrap; }
        .cart-item-controls { display: flex; align-items: center; gap: 8px; margin-top: 6px; }
        .qty-btn { width: 28px; height: 28px; border-radius: 6px; border: 1.5px solid var(--line); background: #fff; font-size: 16px; font-weight: 700; cursor: pointer; color: var(--ink); display: inline-flex; align-items: center; justify-content: center; }
        .qty-btn:hover { border-color: var(--brand); color: var(--brand); }
        .qty-val { font-weight: 800; font-size: 14px; min-width: 20px; text-align: center; }
        .btn-remove { background: none; border: none; cursor: pointer; color: #b42318; font-size: 13px; font-weight: 700; padding: 0; margin-left: 4px; }
        .btn-remove:hover { text-decoration: underline; }
        .summary-row { display: flex; justify-content: space-between; font-size: 14px; margin: 10px 0; }
        .summary-total { font-size: 18px; font-weight: 800; border-top: 1px solid var(--line); padding-top: 12px; margin-top: 4px; }
        .cart-recovery-note { margin-top: 14px; padding: 12px 14px; border-radius: 10px; background: #f8fbff; border: 1px solid var(--line); color: var(--muted); font-size: 13px; line-height: 1.55; font-weight: 600; }
        .btn-checkout { width: 100%; padding: 15px; font-size: 16px; border-radius: 10px; margin-top: 16px; }
        .btn-continue { width: 100%; padding: 12px; font-size: 14px; border-radius: 10px; margin-top: 8px; background: transparent; border: 1.5px solid var(--line); color: var(--ink); font-weight: 700; cursor: pointer; text-align: center; text-decoration: none; display: block; }
        .btn-continue:hover { border-color: var(--brand); color: var(--brand); }
        @media (max-width: 700px) { .cart-layout { grid-template-columns: 1fr; } }
    </style>
    <?php require_once __DIR__ . '/includes/load-custom-css.php'; ?>
    <?php require_once __DIR__ . '/includes/head-analytics.php'; ?>
</head>
<body>
<?php $svNavCurrent = 'carrinho'; include __DIR__ . '/includes/navbar.php'; ?>

<main class="container cart-page">
    <div class="cart-layout">
        <div class="cart-card">
            <h1 class="cart-title">Meu Carrinho</h1>
            <div id="cart-items-list"></div>
        </div>

        <aside class="cart-card" id="cart-summary">
            <div class="free-shipping-container">
                <div class="free-shipping-text" aria-live="polite"></div>
                <div class="free-shipping-progress-wrapper" hidden>
                    <div class="free-shipping-progress-bar"></div>
                </div>
            </div>
            <h2 class="cart-title" style="font-size:18px">Resumo do pedido</h2>
            <div class="summary-row"><span>Subtotal</span><strong id="cart-subtotal" class="cart-subtotal-value">—</strong></div>
            <div class="summary-row"><span>Frete</span><strong id="cart-frete">A calcular</strong></div>
            <div class="summary-row summary-total"><span>Total estimado</span><strong id="cart-total">—</strong></div>
            <div class="frete-calc">
                <label for="frete-cep" style="font-size:12px;font-weight:700;color:var(--muted);display:block;margin-bottom:6px">Calcular frete</label>
                <div style="display:flex;gap:8px">
                    <input type="text" id="frete-cep" aria-label="CEP para calcular o frete" inputmode="numeric" autocomplete="postal-code" maxlength="9" style="flex:1;padding:10px 12px;border-radius:8px;border:1.5px solid var(--line);font-size:13px">
                    <button type="button" class="btn btn-secondary" id="btn-frete" style="white-space:nowrap;padding:10px 14px">Calcular</button>
                </div>
                <div id="frete-status" style="font-size:12px;color:var(--muted);margin-top:8px;line-height:1.5"></div>
            </div>
            <div class="cart-recovery-note">Seu carrinho é salvo localmente para que você possa continuar a compra quando voltar.</div>
            <a href="/checkout" class="btn btn-primary btn-checkout" id="btn-checkout">Finalizar pedido</a>
            <div id="checkout-validate-status" style="font-size:13px;color:#b00020;margin-top:8px;line-height:1.5"></div>
            <a href="/catalogo" class="btn-continue">Continuar comprando</a>
            <div class="sv-trust-badge">
                <svg viewBox="0 0 24 24" style="width: 28px; height: 28px; flex-shrink: 0; fill: #35c759;"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>
                <div><strong>Compra 100% Segura</strong><br><span>Ambiente criptografado com Checkout PIX rápido.</span></div>
            </div>
            <div style="margin-top:16px;padding:12px;background:#f8fafc;border:1px solid var(--line);border-radius:10px;display:grid;gap:8px">
                <div style="font-size:12px;color:var(--ink);font-weight:700">💳 Formas de pagamento aceitas:</div>
                <div style="font-size:12px;color:var(--muted);line-height:1.4">
                    ⚡ <strong>PIX disponível</strong><br>
                    💳 <strong>Cartão de crédito</strong> com condições apresentadas pelo gateway<br>
                    📄 <strong>Boleto bancário</strong> disponível pelo Mercado Pago
                </div>
            </div>
            <div style="margin-top:16px;display:grid;gap:6px">
                <div style="font-size:12px;color:var(--muted);font-weight:600">🔒 Compra segura e dados protegidos</div>
                <div style="font-size:12px;color:var(--muted);font-weight:600">🚚 Envio rápido para todo Brasil</div>
                <div style="font-size:12px;color:var(--muted);font-weight:600">↩️ 7 dias corridos para troca ou devolução</div>
            </div>
        </aside>
    </div>
</main>

<footer>
    <div class="container">
        <div class="footer-cols">
            <div><strong>Vivaliz</strong><p>Qualidade e entrega rápida para todo o Brasil.</p></div>
            <div><strong>Navegação</strong><a href="/catalogo">Produtos</a><a href="/sobre">Sobre</a><a href="/contato">Contato</a></div>
            <div><strong>Atendimento</strong><a href="/contato">Fale conosco</a><a href="/faq">Dúvidas frequentes</a><a href="/politica-privacidade">Privacidade</a></div>
        </div>
        <p class="footer-copy">&copy; 2026 Vivaliz. Todos os direitos reservados.</p>
    </div>
</footer>

<script>
(function () {
    function getCart() {
        if (window.ShopVivalizCart && typeof window.ShopVivalizCart.get === 'function') return window.ShopVivalizCart.get();
        try { return JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]'); } catch(e) { return []; }
    }
    function saveCart(items) {
        if (window.ShopVivalizCart && typeof window.ShopVivalizCart.set === 'function') { window.ShopVivalizCart.set(items); return; }
        localStorage.setItem('shopvivaliz_cart', JSON.stringify(items));
        localStorage.setItem('shopvivaliz_cart_updated_at', String(Date.now()));
        window.dispatchEvent(new CustomEvent('shopvivaliz:cart-updated', { detail: { items: items } }));
    }
    function clearShippingQuote() { localStorage.removeItem('shopvivaliz_shipping_quote'); }
    function fmtMoney(v) {
        if (!v || isNaN(v)) return 'Consulte o valor';
        return 'R$ ' + parseFloat(v).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }
    function svEscHtml(value) {
        if (value === null || value === undefined) return '';
        return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function svSafeImageUrl(url) {
        var fallback = '/images/logo-vivaliz-square-v2.png';
        if (!url) return fallback;
        var clean = String(url).trim();
        if (!/^(https?:\/\/|\/)/i.test(clean)) return fallback;
        return svEscHtml(clean);
    }
    function setCheckoutAvailability(btnCheckout, enabled) {
        if (!btnCheckout) return;
        if (!enabled) {
            btnCheckout.style.opacity = '0.5';
            btnCheckout.style.pointerEvents = 'none';
            btnCheckout.setAttribute('aria-disabled', 'true');
            btnCheckout.setAttribute('tabindex', '-1');
            return;
        }
        btnCheckout.style.opacity = '1';
        btnCheckout.style.pointerEvents = '';
        btnCheckout.removeAttribute('aria-disabled');
        btnCheckout.removeAttribute('tabindex');
    }
    function initFreeShippingVisual() {
        var bars = document.querySelectorAll('.free-shipping-progress-bar');
        var texts = document.querySelectorAll('.free-shipping-text');
        var wrappers = Array.from(bars).map(function (bar) { return bar.closest('.free-shipping-progress-wrapper, .free-shipping-rewards, .gamification-rewards-container') || bar; });
        if (!bars.length) return;
        texts.forEach(function (t) { t.textContent = ''; });
        wrappers.forEach(function (w) { w.style.display = 'none'; w.hidden = true; });

        fetch('/api/settings/free-shipping.php')
            .then(function (r) { return r.json(); })
            .then(function (cfg) {
                if (!cfg || !cfg.enabled || !(cfg.threshold > 0)) return;
                var limit = Number(cfg.threshold) || 0;
                if (!(limit > 0)) return;
                window.updateFreeShippingVisual = function () {
                    var items = getCart();
                    if (!items.length) {
                        texts.forEach(function (text) { text.textContent = ''; });
                        wrappers.forEach(function (w) { w.style.display = 'none'; w.hidden = true; });
                        return;
                    }
                    wrappers.forEach(function (w) { w.style.display = ''; w.hidden = false; });
                    var subtotalEl = document.getElementById('cart-subtotal');
                    if (!subtotalEl) return;
                    var totalStr = (subtotalEl.textContent || '').replace('R$', '').replace(/\./g, '').replace(',', '.').trim();
                    var currentTotal = parseFloat(totalStr) || 0;
                    var percentage = Math.min(100, (currentTotal / limit) * 100);
                    bars.forEach(function (bar) { bar.style.width = percentage + '%'; bar.classList.toggle('bg-success', currentTotal >= limit); });
                    texts.forEach(function (text) {
                        if (currentTotal >= limit) text.innerHTML = '🎉 Parabéns! Você ganhou <strong>Frete Grátis</strong>!';
                        else {
                            var remaining = (limit - currentTotal).toFixed(2).replace('.', ',');
                            text.innerHTML = 'Faltam apenas <strong>R$ ' + remaining + '</strong> para você ganhar <strong>Frete Grátis!</strong>';
                        }
                    });
                };
                window.updateFreeShippingVisual();
            })
            .catch(function () {});
    }

    function render() {
        var items = getCart();
        var list = document.getElementById('cart-items-list');
        var badge = document.getElementById('nav-cart-count');
        var subtotalEl = document.getElementById('cart-subtotal');
        var totalEl = document.getElementById('cart-total');
        var btnCheckout = document.getElementById('btn-checkout');
        var totalCount = items.reduce(function(a, i){ return a + (i.quantity || 1); }, 0);
        if (badge) badge.textContent = totalCount > 0 ? totalCount : '';
        if (!list) return;

        if (!items.length) {
            clearShippingQuote();
            list.innerHTML = '<div class="cart-empty"><div style="font-size:48px">🛒</div><p>Seu carrinho está vazio.</p><a href="/catalogo" class="btn btn-primary">Ver catálogo</a></div>';
            if (subtotalEl) subtotalEl.textContent = 'R$ 0,00';
            if (totalEl) totalEl.textContent = 'R$ 0,00';
            setCheckoutAvailability(btnCheckout, false);
            if (typeof window.updateFreeShippingVisual === 'function') window.updateFreeShippingVisual();
            return;
        }

        setCheckoutAvailability(btnCheckout, true);
        var total = 0;
        var hasPrice = false;
        var html = '';
        items.forEach(function(it, idx) {
            var price = parseFloat(it.price) || 0;
            var sub = price * (it.quantity || 1);
            total += sub;
            if (price > 0) hasPrice = true;
            var itName = svEscHtml(it.name || it.sku);
            var itSku = svEscHtml(it.sku);
            var itImg = svSafeImageUrl(it.image_url);
            html += '<div class="cart-item">'
                + '<img src="' + itImg + '" alt="' + itName + '" width="80" height="80" loading="lazy" decoding="async" onerror="this.src=\'/images/logo-vivaliz-square-v2.png\'">'
                + '<div class="cart-item-info"><strong>' + itName + '</strong><span>SKU: ' + itSku + '</span>'
                + '<div class="cart-item-controls"><button class="qty-btn" data-idx="' + idx + '" data-delta="-1">−</button>'
                + '<span class="qty-val">' + (it.quantity || 1) + '</span><button class="qty-btn" data-idx="' + idx + '" data-delta="1">+</button>'
                + '<button class="btn-remove" data-remove="' + idx + '">Remover</button></div></div>'
                + '<div class="cart-item-price">' + (price > 0 ? fmtMoney(sub) : 'Consultar') + '</div></div>';
        });
        list.innerHTML = html;
        var fmt = hasPrice ? fmtMoney(total) : 'Consultar';
        if (subtotalEl) subtotalEl.textContent = fmt;
        if (totalEl) totalEl.textContent = fmt;
        if (typeof window.updateFreeShippingVisual === 'function') window.updateFreeShippingVisual();

        list.querySelectorAll('.qty-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var items2 = getCart();
                var idx2 = parseInt(btn.getAttribute('data-idx'));
                var delta = parseInt(btn.getAttribute('data-delta'));
                if (!items2[idx2]) return;
                items2[idx2].quantity = Math.max(1, (items2[idx2].quantity || 1) + delta);
                saveCart(items2); clearShippingQuote(); render();
            });
        });
        list.querySelectorAll('.btn-remove').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var items2 = getCart();
                var idx2 = parseInt(btn.getAttribute('data-remove'));
                items2.splice(idx2, 1); saveCart(items2); clearShippingQuote(); render();
            });
        });
    }

    render();
    initFreeShippingVisual();
    window.addEventListener('shopvivaliz:cart-updated', render);

    function updateTotalFromQuote() {
        var quote = null;
        try { quote = JSON.parse(localStorage.getItem('shopvivaliz_shipping_quote') || 'null'); } catch(e) {}
        if (quote && quote.total > 0 && getCart().length) {
            var items = getCart();
            var subtotal = items.reduce(function(a, i){ return a + (parseFloat(i.price) || 0) * (i.quantity || 1); }, 0);
            var totalEl = document.getElementById('cart-total');
            if (totalEl) totalEl.textContent = fmtMoney(subtotal + quote.total);
            var freteEl = document.getElementById('cart-frete');
            if (freteEl) freteEl.textContent = fmtMoney(quote.total);
        }
    }
    updateTotalFromQuote();
})();
</script>
<script src="/js/cart-persistence-v23.js" defer></script>
<script src="/js/cart-shipping-v7.js?v=2026-07-26-v2"></script>
</body>
</html>
