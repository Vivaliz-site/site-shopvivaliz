<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: text/html; charset=UTF-8');

$runtimeSecretsFile = __DIR__ . '/config/runtime-secrets.php';
if (is_file($runtimeSecretsFile) && is_readable($runtimeSecretsFile)) {
    $runtimeSecrets = require $runtimeSecretsFile;
    if (is_array($runtimeSecrets)) {
        foreach ($runtimeSecrets as $key => $value) {
            if (!is_string($key) || $key === '' || getenv($key) !== false) {
                continue;
            }
            $stringValue = is_scalar($value) ? (string)$value : '';
            putenv($key . '=' . $stringValue);
            $_ENV[$key] = $stringValue;
        }
    }
}

require_once __DIR__ . '/includes/mercadopago-gateway.php';

$whatsapp = svmp_env('LOJA_WHATSAPP') ?: '551140415850';
$pixKey = svmp_env('LOJA_PIX_KEY');
$pixName = svmp_env('LOJA_PIX_NAME') ?: 'ShopVivaliz';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finalizar Pedido | Vivaliz</title>
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/checkout.css">
    <?php require_once __DIR__ . '/includes/head-analytics.php'; ?>
    <!-- Mercado Pago SDK V2 + Device ID para fraude -->
    <script src="https://sdk.mercadopago.com/js/v2"></script>
    <script src="https://www.mercadopago.com/v2/security.js" output="deviceId"></script>
</head>
<body>
<?php $svNavCurrent = 'checkout'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="checkout-progress">
    <div class="container">
        <div class="progress-steps">
            <div class="step done">🛒 Carrinho</div>
            <div class="step-arrow">›</div>
            <div class="step active">📋 Dados</div>
            <div class="step-arrow">›</div>
            <div class="step">💳 Pagamento</div>
            <div class="step-arrow">›</div>
            <div class="step">✅ Confirmação</div>
        </div>
    </div>
</div>

<div class="container">
    <div class="checkout-timer-banner" style="background:#fffbeb; border:1px solid #fde68a; border-radius:12px; padding:12px 18px; display:flex; align-items:center; gap:12px; margin-top:24px; font-family:'Inter',sans-serif; margin-bottom:-8px; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.05);">
        <span style="font-size:20px;">⏱️</span>
        <div style="flex:1; font-size:13px; color:#92400e; font-weight:700; line-height: 1.4;">
            Garanta o seu estoque! Os produtos no seu carrinho estão reservados por <strong id="checkout-timer-display" style="color:#b45309; font-size:14px;">15:00</strong> minutos.
        </div>
    </div>
</div>

<main class="container checkout-layout" style="padding-top:28px;padding-bottom:56px">

    <!-- FORMULÁRIO -->
    <section class="checkout-card" id="checkout-section">
        <h1 class="checkout-title">Seus dados</h1>
        <div class="checkout-reassurance" aria-label="Informações rápidas do checkout">
            <div class="reassurance-pill">Sem cadastro obrigatório</div>
            <div class="reassurance-copy">
                Finalize o pedido em poucos passos. Se precisar de ajuda, nosso atendimento acompanha a confirmação por WhatsApp.
            </div>
        </div>
        <form id="checkout-form" class="checkout-form" novalidate>
            <div class="form-row-2">
                <label class="form-group">
                    <span>Nome completo *</span>
                    <input name="customer_name" maxlength="120" required autocomplete="name" aria-label="Nome completo">
                </label>
                <label class="form-group">
                    <span>E-mail *</span>
                    <input name="customer_email" type="email" maxlength="160" required autocomplete="email" aria-label="E-mail">
                </label>
            </div>
            <div class="form-row-2">
                <label class="form-group">
                    <span>Telefone / WhatsApp *</span>
                    <input name="customer_phone" maxlength="20" required autocomplete="tel" aria-label="Telefone ou WhatsApp">
                </label>
                <label class="form-group">
                    <span>CEP *</span>
                    <input name="cep" id="cep-input" inputmode="numeric" maxlength="9" required autocomplete="postal-code" aria-label="CEP">
                </label>
            </div>
            <div class="form-row-2">
                <label class="form-group">
                    <span>Rua / avenida *</span>
                    <input name="address" id="address-input" maxlength="300" required autocomplete="address-line1" aria-label="Rua ou avenida">
                </label>
                <label class="form-group">
                    <span>Número *</span>
                    <input name="street_number" id="street-number-input" maxlength="30" required autocomplete="address-line2" aria-label="Número do endereço">
                </label>
            </div>
            <div class="form-row-2">
                <label class="form-group">
                    <span>Bairro *</span>
                    <input name="neighborhood" id="neighborhood-input" maxlength="120" required aria-label="Bairro">
                </label>
                <label class="form-group">
                    <span>Cidade *</span>
                    <input name="city" id="city-input" maxlength="120" required autocomplete="address-level2" aria-label="Cidade">
                </label>
            </div>
            <div class="form-row-2">
                <label class="form-group">
                    <span>Estado (UF) *</span>
                    <input name="state" id="state-input" maxlength="2" minlength="2" required autocomplete="address-level1" aria-label="Estado" style="text-transform:uppercase">
                </label>
                <label class="form-group" id="boleto-cpf-field" hidden>
                    <span>CPF do pagador *</span>
                    <input name="cpf" id="cpf-input" inputmode="numeric" maxlength="14" autocomplete="off" aria-label="CPF do pagador">
                </label>
            </div>

            <div class="payment-select-title">Forma de pagamento *</div>
            <div class="payment-options">
                <label class="payment-opt">
                    <input type="radio" name="payment_method" value="mercado_pago" checked required>
                    <span class="payment-opt-box">
                        <img src="/assets/payments/mercado-pago-official.svg" alt="Mercado Pago" style="max-height:48px; margin-bottom:8px">
                        <strong>Pagar com segurança</strong>
                        <small>Cartão, PIX, Boleto ou saldo em conta</small>
                    </span>
                </label>
            </div>

            <label class="form-group">
                <span>Observações</span>
                <textarea name="notes" rows="3" maxlength="1000" aria-label="Observações do pedido"></textarea>
            </label>

            <button class="btn btn-primary btn-checkout" type="submit" id="submit-btn">
                Confirmar pedido
            </button>
            <div class="checkout-support-inline">
                <strong>Atendimento ágil:</strong>
                <a href="https://wa.me/<?= htmlspecialchars($whatsapp) ?>?text=<?= rawurlencode('Olá! Preciso de ajuda para finalizar meu pedido na Vivaliz.') ?>" target="_blank" rel="noreferrer">
                    fale no WhatsApp antes de concluir
                </a>
            </div>
            <div id="checkout-status" class="checkout-status-msg"></div>
        </form>
    </section>

    <!-- RESUMO -->
    <aside class="checkout-card checkout-summary-card">
        <h2 class="checkout-title">Resumo</h2>
        <div id="cart-items" class="summary-items"></div>

        <div class="summary-totals">
            <div class="summary-row">
                <span>Subtotal</span>
                <strong id="cart-subtotal">—</strong>
            </div>
            <div class="summary-row">
                <span>Frete</span>
                <strong id="cart-shipping">A calcular</strong>
            </div>
            <div class="summary-row summary-total">
                <span>Total estimado</span>
                <strong id="cart-total">—</strong>
            </div>
        </div>

        <div class="trust-badges">
            <div class="trust-item">🔒 Compra 100% segura</div>
            <div class="trust-item">🚚 Envio para todo Brasil</div>
            <div class="trust-item">↩️ 30 dias para troca</div>
        </div>
    </aside>
</main>

<!-- MODAL PIX -->
<div id="pix-modal" class="modal-overlay" hidden>
    <div class="modal-box">
        <div class="modal-icon">⚡</div>
        <h2>Pagamento via PIX</h2>
        <p>Copie a chave abaixo e faça o pagamento no seu banco:</p>
        <div class="pix-key-box">
            <span id="pix-key-display"><?= htmlspecialchars($pixKey) ?></span>
            <button class="btn-copy" onclick="svCopyPix()">Copiar</button>
        </div>
        <p class="pix-name">Beneficiário: <strong><?= htmlspecialchars($pixName) ?></strong></p>
        <p class="pix-amount">Valor: <strong id="pix-amount-display">—</strong></p>
        <p class="muted" style="font-size:13px">Após o pagamento, você receberá a confirmação por e-mail ou WhatsApp.</p>
        <div class="modal-actions">
            <a id="wpp-confirm-link" href="#" target="_blank" class="btn btn-wpp">
                💬 Confirmar pelo WhatsApp
            </a>
            <button onclick="document.getElementById('pix-modal').hidden=true" class="btn btn-secondary">
                Fechar
            </button>
        </div>
    </div>
</div>

<!-- MODAL BOLETO MERCADO PAGO -->
<div id="boleto-modal" class="modal-overlay" hidden>
    <div class="modal-box">
        <div class="modal-icon">🧾</div>
        <h2>Boleto Mercado Pago emitido</h2>
        <p id="boleto-order-msg" style="font-weight:700;color:#0f8f62;font-size:18px"></p>
        <label class="form-group" id="boleto-line-group">
            <span>Linha digitável</span>
            <textarea id="boleto-digitable-line" rows="3" readonly aria-label="Linha digitável do boleto"></textarea>
        </label>
        <div class="modal-actions">
            <button type="button" onclick="svCopyBoletoLine()" class="btn btn-secondary">Copiar linha</button>
            <a id="boleto-open-link" href="#" target="_blank" rel="noopener noreferrer" class="btn btn-primary">Abrir boleto</a>
        </div>
        <p class="muted" style="font-size:13px">O pedido será confirmado somente após a compensação informada pelo Mercado Pago.</p>
    </div>
</div>

<!-- MODAL SUCESSO -->
<div id="success-modal" class="modal-overlay" hidden>
    <div class="modal-box">
        <div class="modal-icon">🎉</div>
        <h2>Pedido registrado!</h2>
        <p id="order-number-msg" style="font-weight:700;color:#0f8f62;font-size:18px"></p>
        <p>Nossa equipe comercial já seguirá com a confirmação de frete e pagamento.</p>
        <div class="modal-actions">
            <a id="success-wpp-link" href="#" target="_blank" class="btn btn-wpp">
                💬 Falar no WhatsApp
            </a>
            <a href="/catalogo" class="btn btn-secondary">Continuar comprando</a>
        </div>
    </div>
</div>

<script>
(function () {
    // MercadoPago.js V2 initialization with Public Key
    var PUBLIC_KEY = <?= json_encode(svmp_env('MERCADOPAGO_PUBLIC_KEY')) ?>;
    try {
        if (PUBLIC_KEY && window.MercadoPago) {
            // SDK V2 usa o construtor `new MercadoPago(...)`, nao o metodo
            // estatico `.configure()` da API antiga (V1). O Device ID de
            // fraude ja e coletado automaticamente pelo v2/security.js
            // carregado no <head>, nao precisa de chamada manual.
            new MercadoPago(PUBLIC_KEY, { locale: 'pt-BR' });
        }
    } catch (mpInitError) {
        console.error('MercadoPago SDK init failed', mpInitError);
    }

    var PIX_KEY = <?= json_encode($pixKey) ?>;
    var WPP_NUM = <?= json_encode($whatsapp) ?>;

    /* Carrinho */
    function getCart() {
        try { return JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]'); } catch(e) { return []; }
    }
    function getShippingQuote() {
        try { return JSON.parse(localStorage.getItem('shopvivaliz_shipping_quote') || 'null'); } catch(e) { return null; }
    }
    function clearCart() { localStorage.removeItem('shopvivaliz_cart'); }
    function clearShippingQuote() { localStorage.removeItem('shopvivaliz_shipping_quote'); }
    function fmtMoney(v) {
        if (!v || isNaN(v)) return 'Consulte o valor';
        return 'R$ ' + parseFloat(v).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    /* Renderizar itens do carrinho */
    function renderCart() {
        var items = getCart();
        var el = document.getElementById('cart-items');
        var subEl = document.getElementById('cart-subtotal');
        var totEl = document.getElementById('cart-total');
        var shippingEl = document.getElementById('cart-shipping');
        var badge = document.getElementById('nav-cart-count');
        if (!el) return;

        var total = 0;
        var hasPrice = false;
        var html = '';
        var quote = getShippingQuote();
        var shippingTotal = quote && Number(quote.total || 0) > 0 ? Number(quote.total || 0) : 0;

        if (!items.length) {
            html = '<p class="empty-cart">Carrinho vazio. <a href="/catalogo">Ver produtos</a></p>';
        } else {
            items.forEach(function (it) {
                var price = parseFloat(it.price) || 0;
                var sub = price * (it.quantity || 1);
                total += sub;
                if (price > 0) hasPrice = true;
                html += '<div class="summary-item">'
                    + '<img src="' + (it.image_url || '/images/logo-vivaliz-square.png') + '" alt="" onerror="this.src=\'/images/logo-vivaliz-square.png\'">'
                    + '<div class="summary-item-info">'
                    + '<strong>' + (it.name || it.sku) + '</strong>'
                    + '<span>Qtd: ' + (it.quantity || 1) + ' &nbsp;|&nbsp; ' + (price > 0 ? fmtMoney(sub) : 'Consultar') + '</span>'
                    + '</div></div>';
            });
        }

        el.innerHTML = html;
        var fmt = hasPrice ? fmtMoney(total) : 'Consultar';
        if (subEl) subEl.textContent = fmt;
        if (shippingEl) shippingEl.textContent = shippingTotal > 0 ? fmtMoney(shippingTotal) : 'A calcular';
        if (totEl) totEl.textContent = hasPrice ? fmtMoney(total + shippingTotal) : 'Consultar';
        if (badge) badge.textContent = items.reduce(function(a,i){ return a+(i.quantity||1); }, 0);
    }

    /* CEP auto-fill via ViaCEP */
    var cepInput = document.getElementById('cep-input');
    function fetchAddress(cep) {
        fetch('https://viacep.com.br/ws/' + cep + '/json/')
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d.erro) return;
                var addr = document.getElementById('address-input');
                if (addr) {
                    if (d.logradouro) addr.value = d.logradouro;
                    var neighborhood = document.getElementById('neighborhood-input');
                    var city = document.getElementById('city-input');
                    var state = document.getElementById('state-input');
                    if (neighborhood && d.bairro) neighborhood.value = d.bairro;
                    if (city && d.localidade) city.value = d.localidade;
                    if (state && d.uf) state.value = d.uf;
                    var number = document.getElementById('street-number-input');
                    if (number) number.focus();
                }
            }).catch(function(){});
    }
    if (cepInput) {
        cepInput.addEventListener('input', function () {
            var val = this.value.replace(/\D/g, '');
            if (val.length > 5) {
                this.value = val.substring(0, 5) + '-' + val.substring(5, 8);
            } else {
                this.value = val;
            }
            if (val.length === 8) {
                fetchAddress(val);
            }
        });
        cepInput.addEventListener('blur', function () {
            var val = this.value.replace(/\D/g, '');
            if (val.length === 8) {
                fetchAddress(val);
            }
        });
    }

    function selectedPaymentMethod() {
        var selected = document.querySelector('input[name="payment_method"]:checked');
        return selected ? selected.value : 'pix';
    }

    function updatePaymentFields() {
        var boleto = selectedPaymentMethod() === 'boleto';
        var group = document.getElementById('boleto-cpf-field');
        var input = document.getElementById('cpf-input');
        if (group) group.hidden = !boleto;
        if (input) input.required = boleto;
    }
    document.querySelectorAll('input[name="payment_method"]').forEach(function(input) {
        input.addEventListener('change', updatePaymentFields);
    });
    updatePaymentFields();

    function pendingKey(items, method) {
        return JSON.stringify({method: method, items: items.map(function(item) {
            return [String(item.sku || ''), Number(item.quantity || 1)];
        })});
    }
    function readPendingPayment() {
        try { return JSON.parse(localStorage.getItem('shopvivaliz_pending_payment') || 'null'); } catch (e) { return null; }
    }
    function writePendingPayment(value) { localStorage.setItem('shopvivaliz_pending_payment', JSON.stringify(value)); }
    function clearPendingPayment() { localStorage.removeItem('shopvivaliz_pending_payment'); }

    async function postJson(url, payload) {
        var response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        var data = await response.json().catch(function() { return {}; });
        if (!response.ok || !data.ok) throw new Error(data.message || data.error || 'Falha ao processar pagamento.');
        return data;
    }

    window.svNewCheckoutSubmit = true;
    document.getElementById('checkout-form').addEventListener('submit', async function (e) {
        e.preventDefault();
        var btn = document.getElementById('submit-btn');
        var status = document.getElementById('checkout-status');
        var items = getCart();
        if (!items.length) { status.textContent = 'Carrinho vazio.'; status.className='checkout-status-msg err'; return; }
        if (!this.checkValidity()) { this.reportValidity(); return; }

        btn.disabled = true;
        btn.textContent = 'Registrando pedido…';
        status.textContent = '';
        status.className = 'checkout-status-msg';
        var fd = new FormData(this);
        var payload = { items: items };
        fd.forEach(function(v,k){ payload[k] = v; });
        try {
            var deviceInput = document.querySelector('input[name="deviceId"]');
            payload.device_id = deviceInput ? deviceInput.value : (window.deviceId || '');
        } catch (e) {}
        try {
            var q = JSON.parse(localStorage.getItem('shopvivaliz_shipping_quote') || 'null');
            if (q) {
                payload.shipping_total = Number(q.total) || 0;
                payload.shipping_label = q.label || '';
                payload.shipping_service = q.option && q.option.id ? q.option.id : '';
                payload.shipping_cep = q.cep || payload.cep || '';
                payload.shipping_quote_id = q.quote_id || '';
                payload.shipping_expires_at = Number(q.expires_at) || 0;
            }
        } catch (ignore) {}

        var method = fd.get('payment_method') || 'pix';
        var key = pendingKey(items, method);
        try {
            var pending = readPendingPayment();
            var order = pending && pending.key === key && pending.order_number && pending.payment_session_token
                ? pending
                : await postJson('/api/orders/create.php', payload);
            if ((method === 'boleto' || method === 'mercado_pago') && !order.payment_session_token) {
                throw new Error('Sessão segura de pagamento não foi criada.');
            }
            if (order.payment_session_token) {
                writePendingPayment({key: key, method: method, order_number: order.order_number, payment_session_token: order.payment_session_token});
            }

            var shippingQuote = getShippingQuote();
            var shippingTotal = shippingQuote && Number(shippingQuote.total || 0) > 0 ? Number(shippingQuote.total || 0) : 0;
            var total = items.reduce(function(a,i){ return a+(parseFloat(i.price)||0)*(i.quantity||1); }, 0) + shippingTotal;
            var totalFmt = fmtMoney(total);
            var name = fd.get('customer_name') || '';
            var wppMsg = encodeURIComponent('Olá! Acabei de fazer um pedido na Vivaliz.\nNº: ' + order.order_number + '\nNome: ' + name + '\nTotal: ' + (total > 0 ? totalFmt : 'Consultar') + '\nFavor confirmar frete e pagamento.');
            var wppNumber = String(WPP_NUM || '').replace(/\D/g, '');
            var wppLink = wppNumber ? ('https://wa.me/' + wppNumber + '?text=' + wppMsg) : '/contato';

            if (method === 'boleto') {
                btn.textContent = 'Emitindo boleto…';
                var boleto = await postJson('/api/mercadopago/create-boleto.php', {
                    order_number: order.order_number,
                    payment_session_token: order.payment_session_token
                });
                clearPendingPayment(); clearCart(); clearShippingQuote(); renderCart();
                document.getElementById('boleto-order-msg').textContent = 'Pedido ' + order.order_number;
                document.getElementById('boleto-digitable-line').value = boleto.digitable_line || '';
                document.getElementById('boleto-line-group').hidden = !boleto.digitable_line;
                document.getElementById('boleto-open-link').href = boleto.ticket_url;
                document.getElementById('boleto-modal').hidden = false;
            } else if (method === 'mercado_pago') {
                btn.textContent = 'Abrindo Mercado Pago…';
                var preference = await postJson('/api/mercadopago/create-preference.php', {
                    order_number: order.order_number,
                    payment_session_token: order.payment_session_token
                });
                clearPendingPayment(); clearCart(); clearShippingQuote();
                window.location.assign(preference.checkout_url);
                return;
            } else {
                clearPendingPayment(); clearCart(); clearShippingQuote(); renderCart();
                if (method === 'pix') {
                    document.getElementById('pix-amount-display').textContent = total > 0 ? totalFmt : 'Confirmar com a loja';
                    document.getElementById('wpp-confirm-link').href = wppLink;
                    document.getElementById('pix-modal').hidden = false;
                } else {
                    document.getElementById('order-number-msg').textContent = 'Pedido ' + order.order_number;
                    document.getElementById('success-wpp-link').href = wppLink;
                    document.getElementById('success-modal').hidden = false;
                }
            }
        } catch (err) {
            status.textContent = err && err.message ? err.message : 'Erro de conexão. Tente novamente.';
            status.className='checkout-status-msg err';
        } finally {
            btn.disabled = false;
            btn.textContent = 'Confirmar pedido';
        }
    });

    /* Submit legado: mantido sem execução durante a transição */
    document.getElementById('checkout-form').addEventListener('submit', function (e) {
        e.preventDefault();
        if (window.svNewCheckoutSubmit) return;
        var btn = document.getElementById('submit-btn');
        var status = document.getElementById('checkout-status');
        var items = getCart();
        if (!items.length) { status.textContent = 'Carrinho vazio.'; status.className='checkout-status-msg err'; return; }

        btn.disabled = true;
        btn.textContent = 'Enviando…';
        status.textContent = '';
        status.className = 'checkout-status-msg';

        var fd = new FormData(this);
        var payload = { items: items };
        fd.forEach(function(v,k){ payload[k] = v; });

        // Add shipping quote details
        try {
            var q = JSON.parse(localStorage.getItem('shopvivaliz_shipping_quote') || 'null');
            if (q) {
                payload['shipping_total'] = Number(q.total) || 0;
                payload['shipping_label'] = q.label || '';
                payload['shipping_service'] = q.option && q.option.id ? q.option.id : '';
                payload['shipping_cep'] = q.cep || payload['cep'] || '';
                payload['shipping_quote_id'] = q.quote_id || '';
                payload['shipping_expires_at'] = Number(q.expires_at) || 0;
            }
        } catch (err) {}

        fetch('/api/orders/create.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(r){ return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            btn.textContent = 'Confirmar pedido';
            if (!d.ok) { status.textContent = d.message || d.error || 'Erro ao registrar pedido.'; status.className='checkout-status-msg err'; return; }

            var method = fd.get('payment_method') || 'pix';
            var shippingQuote = getShippingQuote();
            var shippingTotal = shippingQuote && Number(shippingQuote.total || 0) > 0 ? Number(shippingQuote.total || 0) : 0;
            var total = getCart().reduce(function(a,i){ return a+(parseFloat(i.price)||0)*(i.quantity||1); }, 0) + shippingTotal;
            var totalFmt = fmtMoney(total);
            var name = fd.get('customer_name') || '';
            var phone = (fd.get('customer_phone')||'').replace(/\D/g,'');
            var wppMsg = encodeURIComponent('Olá! Acabei de fazer um pedido na Vivaliz.\nNº: ' + d.order_number + '\nNome: ' + name + '\nTotal: ' + (total > 0 ? totalFmt : 'Consultar') + '\nFavor confirmar frete e pagamento.');
            var wppNumber = String(WPP_NUM || '').replace(/\D/g, '');
            var wppLink = wppNumber ? ('https://wa.me/' + wppNumber + '?text=' + wppMsg) : '/contato';
            clearCart();
            clearShippingQuote();
            renderCart();

            if (method === 'pix') {
                document.getElementById('pix-amount-display').textContent = total > 0 ? totalFmt : 'Confirmar com a loja';
                document.getElementById('wpp-confirm-link').href = wppLink;
                document.getElementById('pix-modal').hidden = false;
            } else {
                document.getElementById('order-number-msg').textContent = 'Pedido ' + d.order_number;
                var successCopy = document.querySelector('#success-modal p:not(#order-number-msg)');
                if (successCopy) {
                    successCopy.textContent = method === 'boleto'
                        ? 'Nossa equipe vai emitir o boleto apos confirmar frete e estoque.'
                        : 'Nossa equipe comercial já seguirá com a confirmação de frete e pagamento.';
                }
                document.getElementById('success-wpp-link').href = wppLink;
                document.getElementById('success-modal').hidden = false;
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            btn.textContent = 'Confirmar pedido';
            status.textContent = 'Erro de conexão. Tente novamente.';
            status.className = 'checkout-status-msg err';
        });
    });

    window.svCopyPix = function() {
        navigator.clipboard.writeText(PIX_KEY).then(function(){
            var btn = document.querySelector('.btn-copy');
            if (btn) { btn.textContent = 'Copiado!'; setTimeout(function(){ btn.textContent = 'Copiar'; }, 2000); }
        });
    };

    window.svCopyBoletoLine = function() {
        var line = document.getElementById('boleto-digitable-line');
        if (line && line.value) navigator.clipboard.writeText(line.value);
    };

    renderCart();

    /* Timer regressivo de reserva */
    (function() {
        var duration = 15 * 60; // 15 minutos em segundos
        var display = document.getElementById('checkout-timer-display');
        if (!display) return;

        var timer = duration, minutes, seconds;
        var interval = setInterval(function () {
            minutes = parseInt(String(timer / 60), 10);
            seconds = parseInt(String(timer % 60), 10);

            minutes = minutes < 10 ? "0" + minutes : minutes;
            seconds = seconds < 10 ? "0" + seconds : seconds;

            display.textContent = minutes + ":" + seconds;

            if (--timer < 0) {
                clearInterval(interval);
                display.textContent = "00:00";
                var banner = document.querySelector('.checkout-timer-banner');
                if (banner) {
                    banner.style.background = '#fef2f2';
                    banner.style.borderColor = '#fee2e2';
                    var txtDiv = banner.querySelector('div');
                    if (txtDiv) {
                        txtDiv.style.color = '#991b1b';
                        txtDiv.innerHTML = '⚠️ O tempo de reserva do seu carrinho expirou, mas você ainda pode finalizar a compra!';
                    }
                }
            }
        }, 1000);
    })();
})();
</script>
</body>
</html>
