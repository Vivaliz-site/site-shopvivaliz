/**
 * Galeria leve de imagens do produto.
 *
 * Mantém setas, teclado e swipe na página de produto, mas não alterna imagens
 * automaticamente em cards da home/catálogo. Isso evita baixar galerias inteiras
 * em segundo plano a cada 3 segundos quando o cliente nem interagiu com o card.
 */
(function () {
  'use strict';

  function injectStyles() {
    if (document.getElementById('sv-product-gallery-navigation-styles')) return;

    const style = document.createElement('style');
    style.id = 'sv-product-gallery-navigation-styles';
    style.textContent = `
      #product-zoom-box.sv-product-gallery-enabled { position: relative; }
      .sv-product-gallery-arrow {
        position: absolute;
        top: 50%;
        z-index: 6;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 48px;
        height: 48px;
        padding: 0;
        border: 1px solid rgba(15, 23, 42, 0.16);
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.94);
        color: #173b63;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.18);
        cursor: pointer;
        transform: translateY(-50%);
        transition: transform 160ms ease, background 160ms ease, box-shadow 160ms ease;
        -webkit-tap-highlight-color: transparent;
      }
      .sv-product-gallery-arrow:hover {
        background: #fff;
        box-shadow: 0 10px 28px rgba(15, 23, 42, 0.24);
        transform: translateY(-50%) scale(1.06);
      }
      .sv-product-gallery-arrow:focus-visible {
        outline: 3px solid rgba(11, 79, 136, 0.34);
        outline-offset: 3px;
      }
      .sv-product-gallery-arrow--previous { left: 14px; }
      .sv-product-gallery-arrow--next { right: 14px; }
      .sv-product-gallery-arrow svg { width: 24px; height: 24px; pointer-events: none; }
      .sv-product-gallery-counter {
        position: absolute;
        right: 14px;
        bottom: 14px;
        z-index: 6;
        min-width: 54px;
        padding: 5px 10px;
        border-radius: 999px;
        background: rgba(15, 23, 42, 0.78);
        color: #fff;
        font-size: 0.78rem;
        font-weight: 700;
        line-height: 1.2;
        text-align: center;
        pointer-events: none;
      }
      @media (max-width: 640px) {
        .sv-product-gallery-arrow { width: 44px; height: 44px; }
        .sv-product-gallery-arrow--previous { left: 8px; }
        .sv-product-gallery-arrow--next { right: 8px; }
        .sv-product-gallery-counter { right: 8px; bottom: 8px; }
      }
      @media (prefers-reduced-motion: reduce) {
        .sv-product-gallery-arrow { transition: none; }
      }
    `;
    document.head.appendChild(style);
  }

  function createArrow(direction, label) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'sv-product-gallery-arrow sv-product-gallery-arrow--' + direction;
    button.setAttribute('aria-label', label);
    button.innerHTML = direction === 'previous'
      ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>'
      : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18l6-6-6-6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    return button;
  }

  function initProductGallery() {
    const thumbnails = Array.from(document.querySelectorAll('.product-gallery-thumbnails .thumb-btn'));
    const gallery = document.getElementById('product-zoom-box');
    if (!gallery || thumbnails.length < 2 || gallery.dataset.svGalleryBound === '1') return;

    gallery.dataset.svGalleryBound = '1';
    injectStyles();

    let current = Math.max(0, thumbnails.findIndex(button => button.classList.contains('active')));
    let touchStartX = null;
    let programmatic = false;

    gallery.classList.add('sv-product-gallery-enabled');
    gallery.setAttribute('role', 'region');
    gallery.setAttribute('aria-label', 'Galeria de imagens do produto');
    if (!gallery.hasAttribute('tabindex')) gallery.setAttribute('tabindex', '0');

    const previous = createArrow('previous', 'Ver imagem anterior');
    const next = createArrow('next', 'Ver próxima imagem');
    const counter = document.createElement('span');
    counter.className = 'sv-product-gallery-counter';
    counter.setAttribute('aria-hidden', 'true');
    gallery.append(previous, next, counter);

    function normalize(index) {
      return (index + thumbnails.length) % thumbnails.length;
    }

    function updateControls() {
      counter.textContent = (current + 1) + ' / ' + thumbnails.length;
      previous.setAttribute('aria-label', 'Ver imagem anterior. Imagem atual ' + (current + 1) + ' de ' + thumbnails.length);
      next.setAttribute('aria-label', 'Ver próxima imagem. Imagem atual ' + (current + 1) + ' de ' + thumbnails.length);
    }

    function select(index) {
      current = normalize(index);
      const target = thumbnails[current];
      if (!target) return;
      programmatic = true;
      try {
        target.click();
      } finally {
        programmatic = false;
      }
      updateControls();
    }

    thumbnails.forEach((button, index) => {
      button.addEventListener('click', () => {
        current = index;
        if (!programmatic) updateControls();
      });
    });

    previous.addEventListener('click', event => {
      event.preventDefault();
      event.stopPropagation();
      select(current - 1);
    });
    next.addEventListener('click', event => {
      event.preventDefault();
      event.stopPropagation();
      select(current + 1);
    });

    gallery.addEventListener('keydown', event => {
      if (event.key === 'ArrowLeft') {
        event.preventDefault();
        select(current - 1);
      } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        select(current + 1);
      }
    });

    gallery.addEventListener('touchstart', event => {
      if (event.touches.length === 1) touchStartX = event.touches[0].clientX;
    }, { passive: true });
    gallery.addEventListener('touchend', event => {
      if (touchStartX === null || event.changedTouches.length !== 1) return;
      const distance = event.changedTouches[0].clientX - touchStartX;
      touchStartX = null;
      if (Math.abs(distance) < 48) return;
      select(current + (distance < 0 ? 1 : -1));
    }, { passive: true });

    updateControls();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initProductGallery, { once: true });
  } else {
    initProductGallery();
  }
})();
