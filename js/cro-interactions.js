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
            // Se o botão principal NÃO está visível
            if (!entry.isIntersecting && window.scrollY > 300) {
                stickyBtn.classList.add('visible');
            } else {
                stickyBtn.classList.remove('visible');
            }
        });
    }, { threshold: 0 });

    observer.observe(mainBtn);

    // Também verifica no scroll (fallback suave)
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
 * Remove a classe 'skeleton' assim que as imagens do produto carregarem
 */
function initSkeletonLoaders() {
    const images = document.querySelectorAll('.product-image-skeleton img, .skeleton img');
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
 * Aplica um efeito de zoom suave ao passar o mouse nas fotos principais
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
            img.style.transform = 'scale(2)'; // 2x zoom
        });

        container.addEventListener('mouseleave', () => {
            img.style.transform = 'scale(1)';
            img.style.transformOrigin = 'center center';
        });
    });
}

/**
 * Social Proof Popup Dinâmico
 * Gera mensagens de compras recentes falsas para aumentar FOMO (Fear Of Missing Out)
 */
function initSocialProofPopup() {
    const popup = document.getElementById('social-proof-popup');
    if (!popup) return;

    const names = ['Maria', 'João', 'Ana', 'Carlos', 'Juliana', 'Rafael', 'Amanda', 'Pedro'];
    const cities = ['São Paulo', 'Rio de Janeiro', 'Belo Horizonte', 'Curitiba', 'Salvador', 'Fortaleza', 'Brasília'];
    
    // Intervalo de 15 a 45 segundos
    function showRandomPopup() {
        const name = names[Math.floor(Math.random() * names.length)];
        const city = cities[Math.floor(Math.random() * cities.length)];
        
        const textElement = popup.querySelector('.proof-text');
        const timeElement = popup.querySelector('.proof-time');
        
        if (textElement) {
            textElement.innerHTML = `<strong>${name}</strong> de ${city} acabou de comprar este produto!`;
        }
        if (timeElement) {
            const minutes = Math.floor(Math.random() * 50) + 1;
            timeElement.innerText = `Há ${minutes} minuto${minutes > 1 ? 's' : ''}`;
        }

        popup.classList.add('show');
        
        // Esconde após 5 segundos
        setTimeout(() => {
            popup.classList.remove('show');
            // Agenda o próximo
            const nextDelay = (Math.floor(Math.random() * 30) + 15) * 1000;
            setTimeout(showRandomPopup, nextDelay);
        }, 5000);
    }

    // Primeiro popup aparece após 10 segundos
    setTimeout(showRandomPopup, 10000);

    // Botão de fechar
    const closeBtn = popup.querySelector('.proof-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', () => {
            popup.classList.remove('show');
        });
    }
}

/**
 * Barra de Progresso - Frete Grátis
 * Atualiza visualmente o quanto falta para o frete grátis (assumindo limite de R$ 299)
 */
function initFreeShippingProgress() {
    const bar = document.querySelector('.free-shipping-progress-bar');
    const text = document.querySelector('.free-shipping-text');
    const cartTotalEl = document.querySelector('.cart-subtotal-value'); // Ex: R$ 150,00
    
    if (!bar || !cartTotalEl) return;

    const FREE_SHIPPING_LIMIT = 299.00;
    
    // Função para atualizar (pode ser chamada após AJAX de carrinho)
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
