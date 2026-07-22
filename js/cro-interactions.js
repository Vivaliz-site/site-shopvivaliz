/**
 * ShopVivaliz - CRO & Micro-Interactions
 * Lida com comportamentos dinâmicos que aumentam a conversão (Social Proof, Sticky Cart, Skeleton Loaders)
 */

document.addEventListener('DOMContentLoaded', function() {
    initStickyAddToCart();
    initSkeletonLoaders();
    initImageHoverZoom();
    initFreeShippingProgress();
});

/**
 * Sticky Add to Cart (Mobile)
 * Mostra o botão flutuante quando o botão principal sai de vista.
 */
function initStickyAddToCart() {
    const mainBtn = document.querySelector('.main-buy-button');
    const stickyBtn = document.querySelector('.sticky-buy-wrapper');
    
    if (!mainBtn || !stickyBtn) return;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (!entry.isIntersecting && window.scrollY > 300) {
                stickyBtn.classList.add('visible');
            } else {
                stickyBtn.classList.remove('visible');
            }
        });
    }, { threshold: 0 });

    observer.observe(mainBtn);

    window.addEventListener('scroll', () => {
        const rect = mainBtn.getBoundingClientRect();
        if (rect.top < 0 && window.scrollY > 300) {
            stickyBtn.classList.add('visible');
        } else {
            stickyBtn.classList.remove('visible');
        }
    }, { passive: true });
}

/**
 * Skeleton Loaders
 */
function initSkeletonLoaders() {
    const images = document.querySelectorAll('.skeleton img, .product-image-skeleton img');
    images.forEach(img => {
        if (img.complete) {
            img.parentElement.classList.remove('skeleton', 'product-image-skeleton');
        } else {
            img.addEventListener('load', () => {
                img.parentElement.classList.remove('skeleton', 'product-image-skeleton');
            });
        }
    });
}

/**
 * Hover Zoom
 */
function initImageHoverZoom() {
    const containers = document.querySelectorAll('.hover-zoom-container');
    
    containers.forEach(container => {
        const img = container.querySelector('img');
        if (!img) return;

        container.addEventListener('mousemove', (e) => {
            const rect = container.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            const xPercent = (x / rect.width) * 100;
            const yPercent = (y / rect.height) * 100;
            
            img.style.transformOrigin = `${xPercent}% ${yPercent}%`;
            img.style.transform = 'scale(1.5)';
        });

        container.addEventListener('mouseleave', () => {
            img.style.transform = 'scale(1)';
            img.style.transformOrigin = 'center center';
        });
    });
}

/**
 * Free Shipping Progress
 */
function initFreeShippingProgress() {
    const bars = document.querySelectorAll('.free-shipping-progress-bar');
    const texts = document.querySelectorAll('.free-shipping-text');
    const cartTotalEl = document.querySelector('.cart-subtotal-value');
    const markers = document.querySelectorAll('[data-free-shipping-marker]');

    if (!bars.length || !cartTotalEl) return;

    const wrappers = Array.from(bars).map(function (bar) {
        return bar.closest('.free-shipping-progress-wrapper, .free-shipping-rewards, .gamification-rewards-container') || bar;
    });

    // Escondido por padrao ate confirmarmos que frete gratis esta habilitado
    // no admin -- evita mostrar "Calculando frete gratis..." indefinidamente
    // quando a loja nunca configurou um valor.
    texts.forEach(function (t) { t.textContent = ''; });
    wrappers.forEach(function (w) { w.style.display = 'none'; });

    fetch('/api/settings/free-shipping.php')
        .then(function (r) { return r.json(); })
        .then(function (cfg) {
            if (!cfg || !cfg.enabled || !(cfg.threshold > 0)) {
                return;
            }
            const FREE_SHIPPING_LIMIT = cfg.threshold;
            wrappers.forEach(function (w) { w.style.display = ''; });
            markers.forEach(function (m) { m.style.display = 'flex'; });

            window.updateFreeShippingVisual = function() {
                let totalStr = cartTotalEl.innerText.replace('R$', '').replace(/\./g, '').replace(',', '.').trim();
                let currentTotal = parseFloat(totalStr) || 0;

                let percentage = (currentTotal / FREE_SHIPPING_LIMIT) * 100;
                if (percentage > 100) percentage = 100;

                bars.forEach(function (bar) {
                    bar.style.width = `${percentage}%`;
                    if (currentTotal >= FREE_SHIPPING_LIMIT) {
                        bar.classList.add('bg-success');
                    } else {
                        bar.classList.remove('bg-success');
                    }
                });

                texts.forEach(function (text) {
                    if (currentTotal >= FREE_SHIPPING_LIMIT) {
                        text.innerHTML = '🎉 Parabéns! Você ganhou <strong>Frete Grátis</strong>!';
                    } else {
                        const remaining = (FREE_SHIPPING_LIMIT - currentTotal).toFixed(2).replace('.', ',');
                        text.innerHTML = `Faltam apenas <strong>R$ ${remaining}</strong> para você ganhar <strong>Frete Grátis!</strong>`;
                    }
                });
            };

            window.updateFreeShippingVisual();
        })
        .catch(function () {});
}

/**
 * Mini-Cart (Side Drawer)
 */
function initMiniCart() {
    const cartLink = document.getElementById('nav-cart-link');
    const overlay = document.getElementById('mini-cart-overlay');
    const drawer = document.getElementById('mini-cart-drawer');
    const closeBtn = document.getElementById('mini-cart-close');
    const body = document.getElementById('mini-cart-body');
    
    if (!drawer) return;
    
    function openCart(e) {
        if (e) e.preventDefault();
        drawer.classList.add('open');
        if (overlay) overlay.classList.add('open');
        renderMiniCart();
    }
    
    function closeCart() {
        drawer.classList.remove('open');
        if (overlay) overlay.classList.remove('open');
    }
    
    if (cartLink) {
        cartLink.addEventListener('click', openCart);
    }
    if (closeBtn) closeBtn.addEventListener('click', closeCart);
    if (overlay) overlay.addEventListener('click', closeCart);
    
    // Bind quantity and remove click actions
    if (body) {
        body.addEventListener('click', function(e) {
            const btn = e.target.closest('button');
            if (!btn) return;
            
            const itemEl = btn.closest('.mini-cart-item');
            if (!itemEl) return;
            
            const sku = itemEl.getAttribute('data-sku');
            const action = btn.getAttribute('data-action');
            
            let items = [];
            try { items = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]'); } catch(err) {}
            
            if (action === 'remove') {
                items = items.filter(function(item) { return item.sku !== sku; });
            } else {
                const item = items.find(function(item) { return item.sku === sku; });
                if (item) {
                    if (action === 'inc') {
                        item.quantity = (parseInt(item.quantity) || 1) + 1;
                    } else if (action === 'dec') {
                        const newQty = (parseInt(item.quantity) || 1) - 1;
                        if (newQty <= 0) {
                            items = items.filter(function(item) { return item.sku !== sku; });
                        } else {
                            item.quantity = newQty;
                        }
                    }
                }
            }
            
            localStorage.setItem('shopvivaliz_cart', JSON.stringify(items));
            
            // Dispatch event so other components (badge, cart page) sync
            window.dispatchEvent(new CustomEvent('shopvivaliz:cart-updated', { detail: { items: items } }));
            
            // Update nav badge count
            const badge = document.getElementById('nav-cart-count');
            if (badge) {
                const count = items.reduce(function(sum, item) { return sum + (Number(item.quantity) || 1); }, 0);
                badge.textContent = count > 0 ? String(count) : '';
            }
            // Update mobile navigation badge
            const mobileBadge = document.getElementById('mobile-cart-count');
            if (mobileBadge) {
                const count = items.reduce(function(sum, item) { return sum + (Number(item.quantity) || 1); }, 0);
                mobileBadge.textContent = count > 0 ? String(count) : '';
                mobileBadge.style.display = count > 0 ? 'inline-flex' : 'none';
            }
            
            renderMiniCart();
        });
    }
    
    // Expose globally to be called when items are added to cart via AJAX
    window.openMiniCart = openCart;
}

function renderMiniCart() {
    const body = document.getElementById('mini-cart-body');
    const totalEl = document.getElementById('mini-cart-total-value');
    if (!body) return;
    
    let items = [];
    try { items = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]'); } catch(e) {}
    
    if (items.length === 0) {
        body.innerHTML = `
            <div class="empty-cart-container" style="text-align:center; padding: 40px 20px; display:flex; flex-direction:column; align-items:center; gap:15px;">
                <div class="empty-cart-icon" style="width: 80px; height: 80px; background: rgba(11, 79, 136, 0.05); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 10px;">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#0b4f88" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="animation: empty-bag-float 3s ease-in-out infinite;">
                        <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                        <line x1="3" y1="6" x2="21" y2="6"></line>
                        <path d="M16 10a4 4 0 0 1-8 0"></path>
                    </svg>
                </div>
                <h3 style="font-size: 16px; margin: 0; color: #333;">Seu carrinho está vazio</h3>
                <p style="font-size: 13px; color: #666; margin: 0 0 10px 0;">Adicione itens do catálogo para começar suas compras.</p>
                <a href="/catalogo" class="btn btn-primary" style="display:inline-block; padding: 10px 24px; font-size:13px; font-weight:bold; border-radius:999px; background:#0b4f88; color:white; text-decoration:none; transition: all 0.2s ease;">Explorar Catálogo</a>
            </div>`;
        if (totalEl) totalEl.innerText = 'R$ 0,00';
        return;
    }
    
    let html = '';
    let total = 0;
    items.forEach(function(item) {
        const price = parseFloat(item.price) || 0;
        const qty = parseInt(item.quantity) || 1;
        total += price * qty;
        html += `<div class="mini-cart-item" data-sku="${item.sku}" style="display:flex; gap:12px; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 12px; align-items: center;">
            <img src="${item.image_url}" style="width:56px; height:56px; object-fit:cover; border-radius:8px; background:#f8fafc; border:1px solid #eee;">
            <div style="flex:1; min-width:0;">
                <div style="font-size:13px; font-weight:bold; margin-bottom:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="${item.name}">${item.name}</div>
                <div style="font-size:13px; color:#0b4f88; font-weight:700; margin-bottom:6px;">R$ ${price.toFixed(2).replace('.', ',')}</div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <div style="display:flex; align-items:center; border:1.5px solid #e2e8f0; border-radius:6px; overflow:hidden; background:#fff;">
                        <button class="mini-cart-qty-btn" data-action="dec" style="width:24px; height:24px; border:0; background:#f8fafc; font-weight:bold; cursor:pointer; color:#1e293b; transition:background 0.2s;">-</button>
                        <span style="padding:0 8px; font-size:12px; font-weight:700; min-width:14px; text-align:center; color:#1e293b;">${qty}</span>
                        <button class="mini-cart-qty-btn" data-action="inc" style="width:24px; height:24px; border:0; background:#f8fafc; font-weight:bold; cursor:pointer; color:#1e293b; transition:background 0.2s;">+</button>
                    </div>
                    <button class="mini-cart-remove-btn" data-action="remove" style="background:none; border:none; color:#dc2626; font-size:11px; font-weight:700; cursor:pointer; padding:4px 8px; border-radius:4px; transition:background 0.2s;">Remover</button>
                </div>
            </div>
        </div>`;
    });
    body.innerHTML = html;
    if (totalEl) totalEl.innerText = 'R$ ' + total.toFixed(2).replace('.', ',');
    
    // Update multi-level gamification rewards track inside mini cart
    const GOAL_COUPON = 150.00;
    const GOAL_SHIPPING = 299.00;
    const bar = document.getElementById('mini-cart-shipping-bar');
    const text = document.getElementById('mini-cart-shipping-text');
    const marker150 = document.getElementById('goal-150-marker');
    const marker299 = document.getElementById('goal-299-marker');
    
    if (bar) {
        let pct = (total / GOAL_SHIPPING) * 100;
        if (pct > 100) pct = 100;
        bar.style.width = pct + '%';
        
        // Handle Level 1 Goal (R$ 150)
        if (total >= GOAL_COUPON) {
            if (marker150) {
                marker150.style.borderColor = '#3b82f6';
                marker150.style.background = '#3b82f6';
                marker150.style.color = '#fff';
                marker150.style.transform = 'translateX(-50%) scale(1.2)';
            }
        } else {
            if (marker150) {
                marker150.style.borderColor = '#cbd5e1';
                marker150.style.background = '#fff';
                marker150.style.color = '#000';
                marker150.style.transform = 'translateX(-50%) scale(1)';
            }
        }
        
        // Handle Level 2 Goal (R$ 299)
        if (total >= GOAL_SHIPPING) {
            if (marker299) {
                marker299.style.borderColor = '#10b981';
                marker299.style.background = '#10b981';
                marker299.style.color = '#fff';
                marker299.style.transform = 'scale(1.2)';
            }
        } else {
            if (marker299) {
                marker299.style.borderColor = '#cbd5e1';
                marker299.style.background = '#fff';
                marker299.style.color = '#000';
                marker299.style.transform = 'scale(1)';
            }
        }
        
        // Set dynamic gamification status messages
        if (total >= GOAL_SHIPPING) {
            if (text) text.innerHTML = '🎉 <strong>Nível Máximo!</strong> Você ganhou <strong>Frete Grátis</strong> e o cupom <strong>VOLTEI5</strong>!';
        } else if (total >= GOAL_COUPON) {
            const rem = (GOAL_SHIPPING - total).toFixed(2).replace('.', ',');
            if (text) text.innerHTML = `🎁 <strong>Cupom VOLTEI5 Liberado!</strong> Faltam <strong>R$ ${rem}</strong> para ganhar <strong>Frete Grátis</strong>!`;
        } else {
            const rem = (GOAL_COUPON - total).toFixed(2).replace('.', ',');
            if (text) text.innerHTML = `Faltam <strong>R$ ${rem}</strong> para desbloquear o cupom de <strong>5% de desconto</strong>!`;
        }
    }
}

/**
 * Page Transitions (Fade-In)
 */
function initPageTransitions() {
    document.body.classList.add('page-loaded');
}

/**
 * Exit-Intent Recovery Pop-up Logic
 */
function initExitIntent() {
    const overlay = document.getElementById('exit-intent-overlay');
    const closeBtn = document.getElementById('exit-intent-close');
    const couponEl = document.getElementById('exit-intent-coupon');
    const timerEl = document.getElementById('exit-intent-timer');
    
    if (!overlay) return;
    
    if (sessionStorage.getItem('sv_exit_intent_shown') === '1') return;
    
    let timerInterval;
    function startTimer(durationSeconds) {
        let remaining = durationSeconds;
        function updateTimer() {
            const min = Math.floor(remaining / 60);
            const sec = remaining % 60;
            if (timerEl) {
                timerEl.textContent = `Oferta expira em: ${min.toString().padStart(2, '0')}:${sec.toString().padStart(2, '0')}`;
            }
            if (remaining <= 0) {
                clearInterval(timerInterval);
                if (timerEl) timerEl.textContent = 'Oferta expirada!';
            }
            remaining--;
        }
        updateTimer();
        timerInterval = setInterval(updateTimer, 1000);
    }
    
    function showPopup() {
        let items = [];
        try { items = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]'); } catch(e) {}
        if (items.length === 0) return;
        
        overlay.classList.add('open');
        sessionStorage.setItem('sv_exit_intent_shown', '1');
        startTimer(15 * 60); // 15 min
    }
    
    function closePopup() {
        overlay.classList.remove('open');
        clearInterval(timerInterval);
    }
    
    document.addEventListener('mouseleave', function(e) {
        if (e.clientY < 5) {
            showPopup();
        }
    });
    
    if (closeBtn) closeBtn.addEventListener('click', closePopup);
    if (overlay) overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closePopup();
    });
    
    if (couponEl) {
        couponEl.addEventListener('click', function() {
            navigator.clipboard.writeText(couponEl.textContent.trim()).then(function() {
                const orig = couponEl.textContent;
                couponEl.textContent = 'Copiado!';
                setTimeout(function() { couponEl.textContent = orig; }, 1500);
            });
        });
    }
}

// Add these to existing DOMContentLoaded listener:
document.addEventListener('DOMContentLoaded', function() {
    initMiniCart();
    initPageTransitions();
    initExitIntent();
});
