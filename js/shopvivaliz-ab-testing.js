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

  // Bootstrap do módulo dedicado de imagens históricas de categoria.
  // Evita manter regra visual dentro deste shim e preserva compatibilidade
  // com a inclusão já existente no index.php.
  if (!document.querySelector('script[data-sv-category-images]')) {
    var script = document.createElement('script');
    script.src = '/js/category-real-images-v52.js?v=20260812-2';
    script.defer = true;
    script.dataset.svCategoryImages = 'historical-20260810';
    document.head.appendChild(script);
  }
})();
