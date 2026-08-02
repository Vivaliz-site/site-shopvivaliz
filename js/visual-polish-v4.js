(function () {
    'use strict';

    if (window.__svVisualPolishV4Initialized) return;
    window.__svVisualPolishV4Initialized = true;

    var scheduled = false;

    function readCart() {
        if (window.ShopVivalizCart && typeof window.ShopVivalizCart.get === 'function') {
            try {
                var apiItems = window.ShopVivalizCart.get();
                return Array.isArray(apiItems) ? apiItems : [];
            } catch (error) {}
        }

        try {
            var stored = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]');
            return Array.isArray(stored) ? stored : [];
        } catch (error) {
            return [];
        }
    }

    function isHomePage() {
        return window.location.pathname === '/' || window.location.pathname === '/index.php';
    }

    function isCartPage() {
        return /^\/carrinho(?:\.php)?\/?$/i.test(window.location.pathname);
    }

    function isCheckoutPage() {
        return /^\/checkout(?:\.php)?\/?$/i.test(window.location.pathname);
    }

    function enhanceCartEmptyState() {
        var host = document.querySelector('#cart-items-list .cart-empty');
        if (!host || host.dataset.svEnhanced === '1') return;

        host.dataset.svEnhanced = '1';
        host.innerHTML = ''
            + '<div class="sv-empty-cart-state">'
            + '<div class="sv-empty-state-icon" aria-hidden="true">🛒</div>'
            + '<h2>Meu carrinho</h2>'
            + '<p>Explore o catálogo e escolha os produtos que combinam com sua casa ou seu trabalho.</p>'
            + '<a href="/catalogo" class="btn btn-primary sv-empty-state-action">Explorar produtos</a>'
            + '</div>';
    }

    function enhanceCheckoutEmptyState() {
        var host = document.getElementById('cart-items');
        if (!host) return;
        if (host.querySelector('.sv-empty-checkout-state')) return;

        host.innerHTML = ''
            + '<div class="sv-empty-checkout-state">'
            + '<div class="sv-empty-state-icon" aria-hidden="true">🧺</div>'
            + '<h2>Adicione produtos antes de finalizar</h2>'
            + '<p>Seu checkout ficará pronto assim que houver itens no carrinho.</p>'
            + '<a href="/catalogo" class="btn btn-primary sv-empty-state-action">Voltar ao catálogo</a>'
            + '</div>';
    }

    function syncPageState() {
        var body = document.body;
        if (!body) return;

        var items = readCart();
        var empty = items.length === 0;
        var home = isHomePage();
        var cart = isCartPage();
        var checkout = isCheckoutPage();

        body.classList.toggle('sv-home-page', home);
        body.classList.toggle('sv-home-top', home && window.scrollY < 260);
        body.classList.toggle('sv-cart-empty', cart && empty);
        body.classList.toggle('sv-checkout-empty', checkout && empty);

        if (cart && empty) enhanceCartEmptyState();
        if (checkout && empty) enhanceCheckoutEmptyState();
    }

    function scheduleSync() {
        if (scheduled) return;
        scheduled = true;
        window.requestAnimationFrame(function () {
            scheduled = false;
            syncPageState();
        });
    }

    function init() {
        syncPageState();

        if (document.body) {
            new MutationObserver(scheduleSync).observe(document.body, {
                childList: true,
                subtree: true
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }

    window.addEventListener('load', scheduleSync, { once: true });
    window.addEventListener('scroll', scheduleSync, { passive: true });
    window.addEventListener('storage', scheduleSync);
    window.addEventListener('shopvivaliz:cart-updated', scheduleSync);
})();
