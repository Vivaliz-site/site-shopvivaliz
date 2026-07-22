/*
 * ShopVivaliz — Dazzle v1 (micro-interações globais)
 * Fallback seguro: sem JS a página fica 100% visível e funcional.
 * - Reveal ao scroll (IntersectionObserver) nos cards das seções
 * - Sombra da navbar ao rolar
 */
(function () {
    'use strict';

    var reduceMotion = window.matchMedia &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* Navbar: sombra ao rolar */
    var navbar = document.querySelector('.sv-navbar');
    if (navbar) {
        var onScroll = function () {
            if (window.scrollY > 12) {
                navbar.classList.add('dz-scrolled');
            } else {
                navbar.classList.remove('dz-scrolled');
            }
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    /* Botão "voltar ao topo" */
    var backTop = document.createElement('button');
    backTop.className = 'dz-back-top';
    backTop.type = 'button';
    backTop.setAttribute('aria-label', 'Voltar ao topo');
    backTop.innerHTML = '↑';
    document.body.appendChild(backTop);
    backTop.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
    });
    var onBackTopScroll = function () {
        backTop.classList.toggle('dz-show', window.scrollY > 600);
    };
    window.addEventListener('scroll', onBackTopScroll, { passive: true });
    onBackTopScroll();

    /* Fade-in de imagens de produto ao carregar */
    document.querySelectorAll('.product-card img, .product-detail-image img').forEach(function (img) {
        if (img.complete) { return; }
        img.classList.add('dz-img-wait');
        var done = function () { img.classList.add('dz-img-loaded'); img.classList.remove('dz-img-wait'); };
        img.addEventListener('load', done, { once: true });
        img.addEventListener('error', done, { once: true });
        // Failsafe: nunca deixar imagem invisível
        setTimeout(done, 3000);
    });

    /* Ripple nos CTAs principais */
    if (!reduceMotion) {
        document.addEventListener('click', function (ev) {
            var btn = ev.target.closest('.btn-primary, .btn-hero-primary, .primary-btn, .btn-checkout, .buy-button, .main-buy-button, .brand-btn');
            if (!btn) { return; }
            var rect = btn.getBoundingClientRect();
            var size = Math.max(rect.width, rect.height);
            var ripple = document.createElement('span');
            ripple.className = 'dz-ripple';
            ripple.style.width = ripple.style.height = size + 'px';
            ripple.style.left = (ev.clientX - rect.left - size / 2) + 'px';
            ripple.style.top = (ev.clientY - rect.top - size / 2) + 'px';
            btn.appendChild(ripple);
            setTimeout(function () { ripple.remove(); }, 600);
        });
    }

    /* Reveal ao scroll */
    if (reduceMotion || !('IntersectionObserver' in window)) {
        return;
    }

    var selectors = [
        '.product-card',
        '.category-slide',
        '.testimonial-card',
        '.brand-card',
        '.brand-kpi',
        '.faq-item',
        '.trust-bar-item',
        '.catalog-trust-item',
        '.cart-item'
    ];

    var items = document.querySelectorAll(selectors.join(','));
    if (!items.length) { return; }

    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('dz-in');
                observer.unobserve(entry.target);
            }
        });
    }, { rootMargin: '120px 0px -8% 0px', threshold: 0.01 });

    var observed = [];
    items.forEach(function (el, i) {
        // Itens de carrossel horizontal ficam de fora (podem estar fora da
        // tela no eixo X e nunca "entrar" verticalmente).
        if (el.closest('.home-scroller-track, .products-track, .categories-track, .hero-carousel-track')) {
            return;
        }
        // Só esconde elementos abaixo da dobra; o que já está visível fica como está.
        var rect = el.getBoundingClientRect();
        if (rect.top > window.innerHeight * 0.9) {
            el.classList.add('dz-will-reveal');
            el.style.transitionDelay = ((i % 4) * 70) + 'ms';
            observer.observe(el);
            observed.push(el);
        }
    });

    // Failsafe: nada pode ficar invisível para sempre.
    setTimeout(function () {
        observed.forEach(function (el) {
            if (!el.classList.contains('dz-in')) {
                el.classList.add('dz-in');
                observer.unobserve(el);
            }
        });
    }, 4000);
})();
