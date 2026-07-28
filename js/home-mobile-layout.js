(() => {
  'use strict';

  const media = window.matchMedia('(max-width: 768px)');
  const main = document.querySelector('#main-content');
  const banner = document.querySelector('.hero-carousel-section');
  const trust = document.querySelector('.trust-bar');
  const categories = document.querySelector('.home-categories');
  const products = Array.from(document.querySelectorAll('section.home-products'))
    .find((section) => !section.classList.contains('home-categories'));
  const hero = document.querySelector('.hero-content');
  const kicker = hero?.querySelector('.hero-kicker, .eyebrow');
  const heroDescription = hero?.querySelector(':scope > p:not(.eyebrow)');

  if (kicker) {
    kicker.remove();
  }

  if (heroDescription) {
    heroDescription.textContent = 'Ferragens, utilidades e soluções para sua casa, com compra segura e entrega em todo o Brasil.';
  }

  if (!main || !banner || !trust || !categories || !products) return;

  const applyOrder = () => {
    if (media.matches) {
      products.insertAdjacentElement('afterend', banner);
    } else {
      trust.insertAdjacentElement('afterend', banner);
    }
  };

  applyOrder();
  if (typeof media.addEventListener === 'function') {
    media.addEventListener('change', applyOrder);
  } else if (typeof media.addListener === 'function') {
    media.addListener(applyOrder);
  }
})();
