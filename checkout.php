<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');

function sv_co_env(string ...$keys): string {
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        $f = __DIR__ . '/.env';
        if (is_file($f)) {
            foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k); $v = trim(trim($v), '"\'');
                if ($k !== '' && getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
            }
        }
    }
    foreach ($keys as $k) {
        $v = getenv($k); if (is_string($v) && $v !== '') return $v;
        if (isset($_ENV[$k]) && $_ENV[$k] !== '') return $_ENV[$k];
    }
    return '';
}

$pixKey   = sv_co_env('LOJA_PIX_KEY')  ?: 'contato@vivaliz.com.br';
$pixName  = sv_co_env('LOJA_PIX_NAME') ?: 'Vivaliz Store';
$whatsapp = sv_co_env('LOJA_WHATSAPP') ?: '5511999999999';
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
    <style>
        /* ── Cupom ── */
        .coupon-row { display:flex; gap:.5rem; margin-bottom:1rem; }
        .coupon-row input { flex:1; }
        .btn-apply-coupon { white-space:nowrap; padding:.55rem 1rem; font-size:.85rem; background:#f0fdf4; border:1px solid #22c55e; color:#166534; border-radius:6px; cursor:pointer; font-weight:600; }
        .btn-apply-coupon:hover { background:#dcfce7; }
        .coupon-msg { font-size:.83rem; margin-top:-.5rem; margin-bottom:.5rem; }
        .coupon-msg.ok  { color:#16a34a; }
        .coupon-msg.err { color:#dc2626; }
        /* ── Economia ── */
        .summary-row.discount { color:#16a34a; font-weight:600; }
        /* ── Auto-fill notice ── */
        .autofill-notice { font-size:.78rem; color:#0369a1; background:#f0f9ff; border:1px solid #bae6fd; border-radius:6px; padding:.45rem .7rem; margin-bottom:.75rem; display:flex; justify-content:space-between; align-items:center; }
        .autofill-notice button { font-size:.75rem; background:none; border:none; color:#0369a1; text-decoration:underline; cursor:pointer; padding:0; }
        /* ── Save-data checkbox ── */
        .save-data-row { display:flex; align-items:center; gap:.5rem; margin-top:.25rem; margin-bottom:1rem; font-size:.83rem; color:#475569; }
        /* ── Inline field errors ── */
        .field-err { font-size:.78rem; color:#dc2626; display:none; margin-top:.2rem; }
        input.invalid, textarea.invalid { border-color:#dc2626 !important; box-shadow:0 0 0 2px #fecaca; }
        /* ── Frete grátis banner ── */
        .free-shipping-bar { background:linear-gradient(90deg,#dcfce7,#bbf7d0); border:1px solid #86efac; border-radius:8px; padding:.55rem 1rem; font-size:.85rem; font-weight:600; color:#15803d; text-align:center; margin-bottom:1rem; }
    </style>
</head>
<body>
<nav class="navbar">
    <div class="container nav-inner">
        <a class="brand-link" href="/">
            <img src="/images/logo-vivaliz.png" alt="Vivaliz" class="brand-logo-img" onerror="this.src='/images/logo.svg'">
        </a>
        <div class="navbar-menu">
            <a href="/catalogo">Catálogo</a>
            <a href="/carrinho.php" class="nav-cart">
                🛒 Carrinho <span class="cart-badge" id="nav-cart-count">0</span>
            </a>
        </div>
    </div>
</nav>

<div class="checkout-progress">
    <div class="container">
        <div class="progress-steps">
            <div class="step done">🛒 Carrinho</div>
            <div class="step-arrow">›</div>
            <div class="step active">📋 Dados &amp; Pagamento</div>
            <div class="step-arrow">›</div>
            <div class="step">✅ Confirmação</div>
        </div>
    </div>
</div>

<main class="container checkout-layout" style="padding-top:28px;padding-bottom:56px">

    <!-- FORMULÁRIO -->
    <section class="checkout-card" id="checkout-section">
        <h1 class="checkout-title">Seus dados</h1>

        <div id="autofill-notice" class="autofill-notice" hidden>
            ✅ Dados preenchidos automaticamente &nbsp;
            <button type="button" onclick="svClearSavedData()">Limpar</button>
        </div>

        <form id="checkout-form" class="checkout-form" novalidate>
            <div class="form-row-2">
                <label class="form-group">
                    <span>Nome completo *</span>
                    <input name="customer_name" id="f-name" maxlength="120" required autocomplete="name" placeholder="João Silva">
                    <span class="field-err" id="err-name">Informe seu nome completo.</span>
                </label>
                <label class="form-group">
                    <span>E-mail *</span>
                    <input name="customer_email" id="f-email" type="email" maxlength="160" required autocomplete="email" placeholder="joao@email.com">
                    <span class="field-err" id="err-email">Informe um e-mail válido.</span>
                </label>
            </div>
            <div class="form-row-2">
                <label class="form-group">
                    <span>Telefone / WhatsApp *</span>
                    <input name="customer_phone" id="f-phone" maxlength="15" required autocomplete="tel" placeholder="(11) 99999-9999" inputmode="tel">
                    <span class="field-err" id="err-phone">Informe um telefone válido.</span>
                </label>
                <label class="form-group">
                    <span>CEP *</span>
                    <input name="cep" id="cep-input" inputmode="numeric" maxlength="9" required autocomplete="postal-code" placeholder="00000-000">
                    <span class="field-err" id="err-cep">CEP inválido.</span>
                </label>
            </div>
            <label class="form-group">
                <span>Endereço completo *</span>
                <input name="address" id="address-input" maxlength="300" required autocomplete="street-address" placeholder="Rua, número, complemento, bairro, cidade/UF">
                <span class="field-err" id="err-address">Informe o endereço completo.</span>
            </label>

            <div class="save-data-row">
                <input type="checkbox" id="save-data-cb" checked>
                <label for="save-data-cb">Salvar meus dados para próximas compras</label>
            </div>

            <div class="payment-select-title">Forma de pagamento *</div>
            <div class="payment-options">
                <label class="payment-opt">
                    <input type="radio" name="payment_method" value="pix" checked>
                    <span class="payment-opt-box">
                        <span class="pay-icon">⚡</span>
                        <strong>PIX</strong>
                        <small>Aprovação imediata</small>
                    </span>
                </label>
                <label class="payment-opt">
                    <input type="radio" name="payment_method" value="whatsapp">
                    <span class="payment-opt-box">
                        <span class="pay-icon">💬</span>
                        <strong>WhatsApp</strong>
                        <small>Fale com a gente</small>
                    </span>
                </label>
                <label class="payment-opt">
                    <input type="radio" name="payment_method" value="transferencia">
                    <span class="payment-opt-box">
                        <span class="pay-icon">🏦</span>
                        <strong>Transferência</strong>
                        <small>TED / DOC</small>
                    </span>
                </label>
            </div>

            <label class="form-group">
                <span>Observações</span>
                <textarea name="notes" rows="3" maxlength="1000" placeholder="Horário de entrega, referência de endereço, cor preferida…"></textarea>
            </label>

            <button class="btn btn-primary btn-checkout" type="submit" id="submit-btn">
                Confirmar pedido
            </button>
            <div id="checkout-status" class="checkout-status-msg"></div>
        </form>
    </section>

    <!-- RESUMO -->
    <aside class="checkout-card checkout-summary-card">
        <h2 class="checkout-title">Resumo</h2>
        <div id="cart-items" class="summary-items"></div>

        <!-- Cupom -->
        <div style="margin-top:1rem">
            <div style="font-size:.82rem;font-weight:600;color:#374151;margin-bottom:.35rem">🏷️ Cupom de desconto</div>
            <div class="coupon-row">
                <input type="text" id="coupon-input" placeholder="CODIGO" maxlength="30" style="text-transform:uppercase">
                <button type="button" class="btn-apply-coupon" onclick="svApplyCoupon()">Aplicar</button>
            </div>
            <div id="coupon-msg" class="coupon-msg"></div>
        </div>

        <div class="summary-totals">
            <div class="summary-row">
                <span>Subtotal</span>
                <strong id="cart-subtotal">—</strong>
            </div>
            <div class="summary-row discount" id="discount-row" style="display:none">
                <span id="discount-label">Desconto</span>
                <strong id="discount-value">—</strong>
            </div>
            <div class="summary-row">
                <span>Frete</span>
                <strong id="frete-label">Calculado na confirmação</strong>
            </div>
            <div class="summary-row summary-total">
                <span>Total estimado</span>
                <strong id="cart-total">—</strong>
            </div>
        </div>

        <div id="free-shipping-banner" class="free-shipping-bar" hidden>
            🚚 Você ganhou <strong>frete grátis</strong>!
        </div>

        <div class="trust-badges">
            <div class="trust-item">🔒 Compra 100% segura</div>
            <div class="trust-item">🚚 Envio para todo Brasil</div>
            <div class="trust-item">↩️ 30 dias para troca</div>
            <div class="trust-item">✅ CNPJ 49.903.300/0001-70</div>
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

<!-- MODAL SUCESSO -->
<div id="success-modal" class="modal-overlay" hidden>
    <div class="modal-box">
        <div class="modal-icon">🎉</div>
        <h2>Pedido registrado!</h2>
        <p id="order-number-msg" style="font-weight:700;color:#0f8f62;font-size:18px"></p>
        <p>Em breve entraremos em contato para confirmar frete e pagamento.</p>
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
    var PIX_KEY = <?= json_encode($pixKey) ?>;
    var WPP_NUM = <?= json_encode($whatsapp) ?>;
    var SAVE_KEY = 'sv_customer_data';
    var CART_KEY = 'shopvivaliz_cart';
    var FREE_SHIPPING_THRESHOLD = 299;

    /* ── Cupom state ── */
    var appliedCoupon = null; // { code, type:'pct'|'fixed', value:Number, label:String }

    /* ── Carrinho ── */
    function getCart() { try { return JSON.parse(localStorage.getItem(CART_KEY) || '[]'); } catch(e) { return []; } }
    function clearCart() { localStorage.removeItem(CART_KEY); }
    function fmtMoney(v) {
        if (!v && v !== 0) return 'Sob consulta';
        return 'R$ ' + parseFloat(v).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    /* ── Máscaras ── */
    function maskPhone(input) {
        input.addEventListener('input', function() {
            var v = this.value.replace(/\D/g,'').slice(0,11);
            if (v.length > 10) {
                v = '(' + v.slice(0,2) + ') ' + v.slice(2,7) + '-' + v.slice(7);
            } else if (v.length > 6) {
                v = '(' + v.slice(0,2) + ') ' + v.slice(2,6) + '-' + v.slice(6);
            } else if (v.length > 2) {
                v = '(' + v.slice(0,2) + ') ' + v.slice(2);
            } else if (v.length > 0) {
                v = '(' + v;
            }
            this.value = v;
        });
    }

    function maskCep(input) {
        input.addEventListener('input', function() {
            var v = this.value.replace(/\D/g,'').slice(0,8);
            if (v.length > 5) v = v.slice(0,5) + '-' + v.slice(5);
            this.value = v;
        });
    }

    /* ── Validação em tempo real ── */
    function attachValidation() {
        var rules = {
            'f-name':  function(v) { return v.trim().split(/\s+/).length >= 2; },
            'f-email': function(v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim()); },
            'f-phone': function(v) { return v.replace(/\D/g,'').length >= 10; },
            'cep-input': function(v) { return v.replace(/\D/g,'').length === 8; },
            'address-input': function(v) { return v.trim().length >= 10; }
        };
        Object.keys(rules).forEach(function(id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('blur', function() {
                var errId = 'err-' + id.replace('f-','').replace('-input','').replace('cep','cep');
                var errEl = document.getElementById(errId);
                if (rules[id](this.value)) {
                    this.classList.remove('invalid');
                    if (errEl) errEl.style.display = 'none';
                } else {
                    this.classList.add('invalid');
                    if (errEl) errEl.style.display = 'block';
                }
            });
        });
    }

    function validateForm() {
        var ok = true;
        var checks = [
            { id:'f-name',      err:'err-name',    fn: function(v){ return v.trim().split(/\s+/).length >= 2; } },
            { id:'f-email',     err:'err-email',   fn: function(v){ return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); } },
            { id:'f-phone',     err:'err-phone',   fn: function(v){ return v.replace(/\D/g,'').length >= 10; } },
            { id:'cep-input',   err:'err-cep',     fn: function(v){ return v.replace(/\D/g,'').length === 8; } },
            { id:'address-input', err:'err-address', fn: function(v){ return v.trim().length >= 10; } }
        ];
        checks.forEach(function(c) {
            var el = document.getElementById(c.id);
            var errEl = document.getElementById(c.err);
            if (!el) return;
            if (c.fn(el.value)) {
                el.classList.remove('invalid');
                if (errEl) errEl.style.display = 'none';
            } else {
                el.classList.add('invalid');
                if (errEl) errEl.style.display = 'block';
                if (ok) { el.focus(); ok = false; }
            }
        });
        return ok;
    }

    /* ── Auto-fill / Salvar dados ── */
    function loadSavedData() {
        var saved = null;
        try { saved = JSON.parse(localStorage.getItem(SAVE_KEY)); } catch(e) {}
        if (!saved) return;
        var map = { 'f-name':'customer_name', 'f-email':'customer_email', 'f-phone':'customer_phone',
                    'cep-input':'cep', 'address-input':'address' };
        var filled = false;
        Object.keys(map).forEach(function(id) {
            var el = document.getElementById(id);
            var val = saved[map[id]];
            if (el && val) { el.value = val; filled = true; }
        });
        if (filled) {
            var notice = document.getElementById('autofill-notice');
            if (notice) notice.hidden = false;
        }
    }

    function saveFormData(fd) {
        if (!document.getElementById('save-data-cb').checked) return;
        var data = {};
        ['customer_name','customer_email','customer_phone','cep','address'].forEach(function(k) {
            data[k] = fd.get(k) || '';
        });
        localStorage.setItem(SAVE_KEY, JSON.stringify(data));
    }

    window.svClearSavedData = function() {
        localStorage.removeItem(SAVE_KEY);
        document.getElementById('autofill-notice').hidden = true;
        ['f-name','f-email','f-phone','cep-input','address-input'].forEach(function(id){
            var el = document.getElementById(id);
            if (el) el.value = '';
        });
    };

    /* ── Cupom ── */
    var COUPONS = {
        'VIVALIZ10': { type:'pct',   value:10,  label:'Desconto 10%' },
        'FRETEGRATIS': { type:'frete', value:0, label:'Frete Grátis' },
        'BEMVINDO5':  { type:'fixed', value:5,  label:'Desconto R$ 5,00' }
    };

    window.svApplyCoupon = function() {
        var input = document.getElementById('coupon-input');
        var msg   = document.getElementById('coupon-msg');
        var code  = (input.value || '').trim().toUpperCase();
        if (!code) { msg.textContent = 'Digite um código.'; msg.className = 'coupon-msg err'; return; }

        /* Tenta API real, fallback para lista local */
        fetch('/api/coupons/validate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: code })
        })
        .then(function(r) { return r.ok ? r.json() : null; })
        .then(function(d) {
            if (d && d.ok) {
                appliedCoupon = { code: code, type: d.type, value: d.value, label: d.label };
                msg.textContent = '✅ ' + d.label + ' aplicado!';
                msg.className = 'coupon-msg ok';
            } else {
                throw new Error('API indisponível');
            }
            renderCart();
        })
        .catch(function() {
            var local = COUPONS[code];
            if (local) {
                appliedCoupon = Object.assign({ code: code }, local);
                msg.textContent = '✅ ' + local.label + ' aplicado!';
                msg.className = 'coupon-msg ok';
            } else {
                appliedCoupon = null;
                msg.textContent = '❌ Cupom inválido ou expirado.';
                msg.className = 'coupon-msg err';
            }
            renderCart();
        });
    };

    /* ── Renderizar resumo ── */
    function renderCart() {
        var items = getCart();
        var el    = document.getElementById('cart-items');
        var subEl = document.getElementById('cart-subtotal');
        var totEl = document.getElementById('cart-total');
        var badge = document.getElementById('nav-cart-count');
        var discRow = document.getElementById('discount-row');
        var discLabel = document.getElementById('discount-label');
        var discVal   = document.getElementById('discount-value');
        var freeBanner = document.getElementById('free-shipping-banner');
        if (!el) return;

        var subtotal = 0;
        var hasPrice = false;
        var html = '';

        if (!items.length) {
            html = '<p class="empty-cart">Carrinho vazio. <a href="/catalogo">Ver produtos</a></p>';
        } else {
            items.forEach(function(it) {
                var price = parseFloat(it.price) || 0;
                var sub   = price * (it.quantity || 1);
                subtotal += sub;
                if (price > 0) hasPrice = true;
                html += '<div class="summary-item">'
                    + '<img src="' + (it.image_url || '/favicon.ico') + '" alt="" onerror="this.src=\'/favicon.ico\'">'
                    + '<div class="summary-item-info">'
                    + '<strong>' + (it.name || it.sku) + '</strong>'
                    + '<span>Qtd: ' + (it.quantity || 1) + ' &nbsp;|&nbsp; ' + (price > 0 ? fmtMoney(sub) : 'Sob consulta') + '</span>'
                    + '</div></div>';
            });
        }

        el.innerHTML = html;
        if (subEl) subEl.textContent = hasPrice ? fmtMoney(subtotal) : 'Sob consulta';
        if (badge) badge.textContent = items.reduce(function(a,i){ return a+(i.quantity||1); }, 0);

        /* Calcular desconto */
        var discount = 0;
        if (appliedCoupon && hasPrice) {
            if (appliedCoupon.type === 'pct')   discount = subtotal * appliedCoupon.value / 100;
            if (appliedCoupon.type === 'fixed')  discount = Math.min(appliedCoupon.value, subtotal);
            if (appliedCoupon.type === 'frete')  discount = 0; // handled in frete label
        }
        if (discount > 0) {
            if (discRow) discRow.style.display = '';
            if (discLabel) discLabel.textContent = appliedCoupon.label;
            if (discVal) discVal.textContent = '- ' + fmtMoney(discount);
        } else {
            if (discRow) discRow.style.display = 'none';
        }

        /* Total */
        var total = Math.max(0, subtotal - discount);
        if (totEl) totEl.textContent = hasPrice ? fmtMoney(total) : 'Sob consulta';

        /* Frete grátis */
        var freteGratis = (appliedCoupon && appliedCoupon.type === 'frete') || subtotal >= FREE_SHIPPING_THRESHOLD;
        if (freeBanner) freeBanner.hidden = !freteGratis || !hasPrice;
        var freteLabel = document.getElementById('frete-label');
        if (freteLabel) {
            if (freteGratis && hasPrice) {
                freteLabel.textContent = 'Grátis 🎉';
                freteLabel.style.color = '#16a34a';
            } else {
                freteLabel.textContent = 'Calculado na confirmação';
                freteLabel.style.color = '';
            }
        }
    }

    /* ── CEP auto-fill ── */
    var cepInput = document.getElementById('cep-input');
    if (cepInput) {
        cepInput.addEventListener('blur', function() {
            var cep = this.value.replace(/\D/g,'');
            if (cep.length !== 8) return;
            fetch('https://viacep.com.br/ws/' + cep + '/json/')
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (d.erro) return;
                    var addr = document.getElementById('address-input');
                    if (addr && !addr.value) {
                        addr.value = d.logradouro + ', ' + d.bairro + ', ' + d.localidade + '/' + d.uf;
                    }
                }).catch(function(){});
        });
    }

    /* ── Submit ── */
    document.getElementById('checkout-form').addEventListener('submit', function(e) {
        e.preventDefault();
        if (!validateForm()) return;

        var btn    = document.getElementById('submit-btn');
        var status = document.getElementById('checkout-status');
        var items  = getCart();
        if (!items.length) { status.textContent = 'Carrinho vazio.'; status.className='checkout-status-msg err'; return; }

        btn.disabled = true;
        btn.textContent = 'Enviando…';
        status.textContent = '';
        status.className = 'checkout-status-msg';

        var fd = new FormData(this);
        saveFormData(fd);

        var subtotal = items.reduce(function(a,i){ return a+(parseFloat(i.price)||0)*(i.quantity||1); }, 0);
        var discount = 0;
        if (appliedCoupon) {
            if (appliedCoupon.type === 'pct')  discount = subtotal * appliedCoupon.value / 100;
            if (appliedCoupon.type === 'fixed') discount = Math.min(appliedCoupon.value, subtotal);
        }
        var total = Math.max(0, subtotal - discount);

        var payload = { items: items, coupon: appliedCoupon, discount: discount };
        fd.forEach(function(v,k){ payload[k] = v; });

        fetch('/api/orders/create.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(r){ return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            btn.textContent = 'Confirmar pedido';
            if (!d.ok) { status.textContent = d.error || 'Erro ao registrar pedido.'; status.className='checkout-status-msg err'; return; }

            var method  = fd.get('payment_method') || 'pix';
            var totalFmt = total > 0 ? fmtMoney(total) : 'Preço sob consulta';
            var name    = fd.get('customer_name') || '';
            var phone   = (fd.get('customer_phone')||'').replace(/\D/g,'');
            var wppMsg  = encodeURIComponent('Olá! Fiz um pedido na Vivaliz.\nNº: ' + d.order_number + '\nNome: ' + name + '\nTotal: ' + totalFmt + (appliedCoupon ? '\nCupom: ' + appliedCoupon.code : '') + '\nFavor confirmar frete e pagamento.');
            var wppLink = 'https://wa.me/' + WPP_NUM + '?text=' + wppMsg;

            clearCart();
            renderCart();

            if (method === 'pix') {
                document.getElementById('pix-amount-display').textContent = total > 0 ? totalFmt : 'Confirmar com a loja';
                document.getElementById('wpp-confirm-link').href = wppLink;
                document.getElementById('pix-modal').hidden = false;
            } else {
                document.getElementById('order-number-msg').textContent = 'Pedido ' + d.order_number;
                document.getElementById('success-wpp-link').href = wppLink;
                document.getElementById('success-modal').hidden = false;
            }
        })
        .catch(function() {
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

    /* ── Init ── */
    maskPhone(document.getElementById('f-phone'));
    maskCep(document.getElementById('cep-input'));
    attachValidation();
    loadSavedData();
    renderCart();
})();
</script>
</body>
</html>
