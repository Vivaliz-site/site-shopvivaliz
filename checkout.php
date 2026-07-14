<?php
declare(strict_types=1);
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

/* PIX key e WhatsApp vindos de .env ou config */
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

$pixKey      = sv_co_env('LOJA_PIX_KEY')     ?: 'contato@vivaliz.com.br';
$pixName     = sv_co_env('LOJA_PIX_NAME')    ?: 'Vivaliz Store';
$whatsapp    = sv_co_env('LOJA_WHATSAPP')    ?: '';
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
            <label class="form-group">
                <span>Endereço completo *</span>
                <input name="address" id="address-input" maxlength="300" required autocomplete="street-address" aria-label="Endereço completo">
            </label>

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
                    <input type="radio" name="payment_method" value="mercado_pago">
                    <span class="payment-opt-box">
                        <span class="pay-icon">💳</span>
                        <strong>Mercado Pago</strong>
                        <small>Cartão, Boleto, PIX</small>
                    </span>
                </label>
                <label class="payment-opt">
                    <input type="radio" name="payment_method" value="pagarme">
                    <span class="payment-opt-box">
                        <span class="pay-icon">🔒</span>
                        <strong>Pagar.me</strong>
                        <small>Cartão de crédito</small>
                    </span>
                </label>
                <label class="payment-opt">
                    <input type="radio" name="payment_method" value="boleto">
                    <span class="payment-opt-box">
                        <span class="pay-icon">🧾</span>
                        <strong>Boleto</strong>
                        <small>Emissão após confirmação</small>
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
                    var addressStr = [d.logradouro, d.bairro, d.localidade + '/' + d.uf].filter(Boolean).join(', ');
                    if (addressStr) {
                        addr.value = addressStr;
                        addr.focus();
                    }
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

    /* Submit */
    document.getElementById('checkout-form').addEventListener('submit', function (e) {
        e.preventDefault();
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
