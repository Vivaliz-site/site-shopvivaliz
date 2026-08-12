/**
 * ShopVivaliz A/B Testing compatibility shim.
 *
 * Os experimentos hard-coded de julho foram encerrados porque alteravam CTA,
 * headline e, principalmente, o cupom exibido para códigos PRIMEIRA10/15 sem
 * validar a promoção comercial vigente. Novos testes devem ser habilitados por
 * configuração revisada e com hipótese/métrica/fonte de verdade explícitas.
 */
(function () {
  'use strict';

  try {
    localStorage.removeItem('sv_ab_variants');
  } catch (error) {}

  var state = {
    version: '2.0-safe',
    active: false,
    experiments: [],
    init: function () { return {}; },
    getStoredVariants: function () { return null; },
    getResults: function () {
      return {
        experiments: [],
        userVariants: null,
        active: false,
        timestamp: new Date().toISOString()
      };
    },
    trackEvent: function () {}
  };

  window.ShopVivalizABTest = state;
})();

/*
 * Restaura as fotos reais de categoria observadas no snapshot da homepage de
 * 10/08/2026. A home já carrega este arquivo, então a correção não depende de
 * adicionar outro <script> ao index.php.
 */
(function () {
  'use strict';

  var historical = [
    {sku:'08R', image:'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/7e4e48e34aee6640663aedd7ea28d01e.jpg'},
    {sku:'VUNA16', image:'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/5c3c7916e7ba4bd0c2814388fe924da7.jpeg'},
    {sku:'1C7Q-LKUT-YLKI', image:'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/fca5312c9f775715ef616d0331a7605c.jpg'},
    {sku:'C08VM', image:'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/60b01ba50aa4062d7ce323bab3158f89.jpg'},
    {sku:'TPJ/AS*BR1', image:'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/ee19ff5b3db206a3c25d5b779bacd30a.jpg'}
  ];

  function restoreCategoryImages() {
    var cards = Array.prototype.slice.call(document.querySelectorAll('.category-slide')).slice(0, 5);
    if (!cards.length) return;

    cards.forEach(function (card, index) {
      var row = historical[index];
      var wrapper = card.querySelector('.category-slide-image-wrapper');
      var title = card.querySelector('strong');
      if (!row || !wrapper) return;

      var image = new Image();
      image.className = 'category-slide-real-image category-slide-img';
      image.alt = title ? title.textContent.trim() : 'Categoria ShopVivaliz';
      image.loading = 'lazy';
      image.decoding = 'async';
      image.width = 320;
      image.height = 220;
      image.onload = function () {
        wrapper.replaceChildren(image);
        card.classList.add('has-real-image');
        card.dataset.categoryImageSku = row.sku;
      };
      image.src = row.image;
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', restoreCategoryImages, { once: true });
  } else {
    restoreCategoryImages();
  }
})();
