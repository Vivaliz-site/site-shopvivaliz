(() => {
  'use strict';

  const media = window.matchMedia('(max-width: 768px)');
  const main = document.querySelector('#main-content');
  const banner = document.querySelector('.hero-carousel-section');
  const trust = document.querySelector('.trust-bar');
  const categories = document.querySelector('.home-categories');
  const products = Array.from(document.querySelectorAll('section.home-products'))
    .find((section) => !section.classList.contains('home-categories'));

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