/**
 * ShopVivaliz - CRO & Micro-Interactions
 * Lida com comportamentos dinâmicos que aumentam a conversão (Social Proof, Sticky Cart, Skeleton Loaders)
 */

document.addEventListener('DOMContentLoaded', function() {
    initStickyAddToCart();
    initSkeletonLoaders();
    initImageHoverZoom();
    initSocialProofPopup();
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
 * Social Proof Popup
 */
function initSocialProofPopup() {
    const popup = document.getElementById('social-proof-popup');
    if (!popup) return;

    const names = ['Maria', 'João', 'Ana', 'Carlos', 'Juliana', 'Rafael', 'Amanda', 'Pedro', 'Lucas', 'Fernanda'];
    const cities = ['São Paulo', 'Rio de Janeiro', 'Belo Horizonte', 'Curitiba', 'Salvador', 'Fortaleza', 'Brasília', 'Porto Alegre'];
    
    function showRandomPopup() {
        const name = names[Math.floor(Math.random() * names.length)];
        const city = cities[Math.floor(Math.random() * cities.length)];
        
        const textElement = popup.querySelector('.proof-text');
        const timeElement = popup.querySelector('.proof-time');
        
        if (textElement) {
            textElement.innerHTML = `<strong>${name}</strong> de ${city} acabou de comprar este produto!`;
        }
        if (timeElement) {
            const minutes = Math.floor(Math.random() * 59) + 1;
            timeElement.innerText = `Há ${minutes} minuto${minutes > 1 ? 's' : ''}`;
        }

        popup.classList.add('show');
        
        setTimeout(() => {
            popup.classList.remove('show');
            const nextDelay = (Math.floor(Math.random() * 30) + 15) * 1000;
            setTimeout(showRandomPopup, nextDelay);
        }, 5000);
    }

    setTimeout(showRandomPopup, 8000);

    const closeBtn = popup.querySelector('.proof-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', () => popup.classList.remove('show'));
    }
}

/**
 * Free Shipping Progress
 */
function initFreeShippingProgress() {
    const bar = document.querySelector('.free-shipping-progress-bar');
    const text = document.querySelector('.free-shipping-text');
    const cartTotalEl = document.querySelector('.cart-subtotal-value');
    
    if (!bar || !cartTotalEl) return;

    const FREE_SHIPPING_LIMIT = 299.00;
    
    window.updateFreeShippingVisual = function() {
        let totalStr = cartTotalEl.innerText.replace('R$', '').replace(/\./g, '').replace(',', '.').trim();
        let currentTotal = parseFloat(totalStr) || 0;
        
        let percentage = (currentTotal / FREE_SHIPPING_LIMIT) * 100;
        if (percentage > 100) percentage = 100;
        
        bar.style.width = `${percentage}%`;
        
        if (currentTotal >= FREE_SHIPPING_LIMIT) {
            bar.classList.add('bg-success');
            if (text) text.innerHTML = '🎉 Parabéns! Você ganhou <strong>Frete Grátis</strong>!';
        } else {
            bar.classList.remove('bg-success');
            const remaining = (FREE_SHIPPING_LIMIT - currentTotal).toFixed(2).replace('.', ',');
            if (text) text.innerHTML = `Faltam apenas <strong>R$ ${remaining}</strong> para você ganhar <strong>Frete Grátis!</strong>`;
        }
    };

    window.updateFreeShippingVisual();
}

/**
 * Mini-Cart (Side Drawer)
 */
function initMiniCart() {
    const cartLink = document.getElementById('nav-cart-link');
    const overlay = document.getElementById('mini-cart-overlay');
    const drawer = document.getElementById('mini-cart-drawer');
    const closeBtn = document.getElementById('mini-cart-close');
    
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
        body.innerHTML = '<p style="text-align:center; padding: 20px;">Seu carrinho está vazio.</p>';
        if (totalEl) totalEl.innerText = 'R$ 0,00';
        return;
    }
    
    let html = '';
    let total = 0;
    items.forEach(function(item) {
        const price = parseFloat(item.price) || 0;
        const qty = parseInt(item.quantity) || 1;
        total += price * qty;
        html += `<div class="mini-cart-item" style="display:flex; gap:10px; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
            <img src="${item.image_url}" style="width:60px; height:60px; object-fit:cover; border-radius:8px;">
            <div style="flex:1;">
                <div style="font-size:13px; font-weight:bold; margin-bottom:5px;">${item.name}</div>
                <div style="font-size:12px; color:#666;">${qty}x R$ ${price.toFixed(2).replace('.', ',')}</div>
            </div>
        </div>`;
    });
    body.innerHTML = html;
    if (totalEl) totalEl.innerText = 'R$ ' + total.toFixed(2).replace('.', ',');
    
    // Update free shipping bar inside mini cart
    const FREE_SHIPPING_LIMIT = 299.00;
    const bar = document.getElementById('mini-cart-shipping-bar');
    const text = document.getElementById('mini-cart-shipping-text');
    if (bar) {
        let pct = (total / FREE_SHIPPING_LIMIT) * 100;
        if (pct > 100) pct = 100;
        bar.style.width = pct + '%';
        if (total >= FREE_SHIPPING_LIMIT) {
            bar.style.backgroundColor = '#10b981';
            if (text) text.innerHTML = '🎉 Você ganhou <strong>Frete Grátis!</strong>';
        } else {
            bar.style.backgroundColor = '#f59e0b';
            if (text) text.innerHTML = `Faltam <strong>R$ ${(FREE_SHIPPING_LIMIT - total).toFixed(2).replace('.', ',')}</strong> para Frete Grátis`;
        }
    }
}

/**
 * Page Transitions (Fade-In)
 */
function initPageTransitions() {
    document.body.classList.add('page-loaded');
}

// Add these to existing DOMContentLoaded listener:
document.addEventListener('DOMContentLoaded', function() {
    initMiniCart();
    initPageTransitions();
});
