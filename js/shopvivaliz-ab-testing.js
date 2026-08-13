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

  // Bootstrap da ponte de aliases de categorias. A selecao principal continua
  // em public-experience-v1.js; este modulo apenas promove fallbacks usando o
  // endpoint real de categorias quando o nome de apresentacao difere do ERP.
  if (!document.querySelector('script[data-sv-category-images]')) {
    var script = document.createElement('script');
    script.src = '/js/category-real-images-v52.js?v=20260813-3';
    script.defer = true;
    script.dataset.svCategoryImages = 'live-alias-20260813';
    document.head.appendChild(script);
  }
})();
