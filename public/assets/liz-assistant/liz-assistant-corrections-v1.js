(() => {
  'use strict';

  const OFFICIAL_LOGO = '/public/assets/liz-assistant/logo-oficial.svg';

  function applyCorrections() {
    const launcher = document.getElementById('sv-liz-launcher');
    if (launcher) {
      const image = launcher.querySelector('img');
      if (image && image.getAttribute('src') !== OFFICIAL_LOGO) {
        image.src = OFFICIAL_LOGO;
        image.alt = 'Liz - Assistente ShopVivaliz';
        image.width = 96;
        image.height = 96;
        image.dataset.svOfficialBrand = '1';
      }
      launcher.setAttribute('aria-label', 'Abrir assistente virtual ShopVivaliz');
    }

    const hero = document.querySelector('#sv-liz-panel .sv-hero');
    if (hero && !hero.querySelector('.sv-liz-official-brand')) {
      hero.innerHTML = '';
      const brand = document.createElement('div');
      brand.className = 'sv-liz-official-brand';
      const logo = document.createElement('img');
      logo.src = OFFICIAL_LOGO;
      logo.alt = 'ShopVivaliz';
      logo.loading = 'lazy';
      logo.decoding = 'async';
      const copy = document.createElement('span');
      copy.textContent = 'Atendimento inteligente ShopVivaliz';
      brand.append(logo, copy);
      hero.appendChild(brand);
    }

    return Boolean(launcher && hero);
  }

  if (applyCorrections()) return;

  const observer = new MutationObserver(() => {
    if (applyCorrections()) observer.disconnect();
  });
  observer.observe(document.documentElement, { childList: true, subtree: true });
  window.setTimeout(() => observer.disconnect(), 15000);
})();
