(function () {
    'use strict';

    var code = 'PRIMEIRA10';
    var dismissedKey = 'shopvivaliz_first_coupon_dismissed_until';
    var pendingKey = 'shopvivaliz_pending_coupon';
    var usedKey = 'shopvivaliz_first_coupon_used';
    var now = Date.now();
    var path = window.location.pathname || '/';

    if (path.indexOf('/checkout') === 0 || path.indexOf('/admin') === 0) return;
    if (document.querySelector('.sv-first-coupon')) return;

    try {
        if (Number(localStorage.getItem(dismissedKey) || '0') > now) return;
        if (localStorage.getItem(usedKey) === code) return;
    } catch (e) {}

    function setDismissed(hours) {
        try {
            localStorage.setItem(dismissedKey, String(Date.now() + hours * 60 * 60 * 1000));
        } catch (e) {}
    }

    function rememberCoupon() {
        try {
            localStorage.setItem(pendingKey, code);
            localStorage.setItem(usedKey, code);
        } catch (e) {}
    }

    function copyCoupon(statusEl) {
        rememberCoupon();
        var done = function () {
            if (statusEl) statusEl.textContent = 'Cupom copiado. Use no checkout.';
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(code).then(done).catch(done);
        } else {
            done();
        }
    }

    function targetHref() {
        var cart = [];
        try { cart = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]'); } catch (e) {}
        return cart && cart.length ? '/checkout' : '/catalogo';
    }

    function openPopup() {
        var popup = document.createElement('section');
        popup.className = 'sv-first-coupon';
        popup.setAttribute('role', 'dialog');
        popup.setAttribute('aria-modal', 'false');
        popup.setAttribute('aria-label', 'Cupom de primeira compra');
        popup.innerHTML = ''
            + '<button type="button" class="sv-first-coupon__close" aria-label="Fechar promocao">&times;</button>'
            + '<div class="sv-first-coupon__body">'
            + '  <div class="sv-first-coupon__eyebrow">Primeira compra</div>'
            + '  <h2>Ganhe <strong>10% OFF</strong> hoje</h2>'
            + '  <p>Use o cupom abaixo no checkout e aproveite sua primeira compra na ShopVivaliz com desconto.</p>'
            + '  <div class="sv-first-coupon__code" aria-label="Codigo do cupom PRIMEIRA10">'
            + '    <span>PRIMEIRA10</span>'
            + '    <button type="button" class="sv-first-coupon__copy">Copiar</button>'
            + '  </div>'
            + '  <div class="sv-first-coupon__actions">'
            + '    <a class="sv-first-coupon__primary" href="' + targetHref() + '">Usar meu cupom</a>'
            + '    <a class="sv-first-coupon__secondary" href="/catalogo">Ver produtos</a>'
            + '  </div>'
            + '  <div class="sv-first-coupon__status" aria-live="polite"></div>'
            + '</div>';

        document.body.appendChild(popup);

        var statusEl = popup.querySelector('.sv-first-coupon__status');
        var closeBtn = popup.querySelector('.sv-first-coupon__close');
        var copyBtn = popup.querySelector('.sv-first-coupon__copy');
        var primary = popup.querySelector('.sv-first-coupon__primary');
        var secondary = popup.querySelector('.sv-first-coupon__secondary');

        closeBtn.addEventListener('click', function () {
            setDismissed(24);
            popup.classList.remove('is-visible');
            setTimeout(function () { popup.remove(); }, 280);
        });

        copyBtn.addEventListener('click', function () {
            copyCoupon(statusEl);
        });

        primary.addEventListener('click', function () {
            copyCoupon(statusEl);
        });

        secondary.addEventListener('click', function () {
            rememberCoupon();
        });

        setTimeout(function () {
            popup.classList.add('is-visible');
        }, 80);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(openPopup, 1800);
        });
    } else {
        setTimeout(openPopup, 1800);
    }
})();
