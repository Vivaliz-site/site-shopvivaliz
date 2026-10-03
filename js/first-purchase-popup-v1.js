/**
 * Legacy first-purchase popup compatibility shim.
 *
 * O popup promocional oficial da home é renderizado por
 * includes/popup-cupons.php e consulta /api/coupons/active.php com cache
 * desabilitado, exibindo somente cupons ativos. Este arquivo permanece como
 * shim porque versões antigas da home ainda podem referenciá-lo em cache.
 */
(function () {
  'use strict';

  try {
    var pendingKey = 'shopvivaliz_pending_coupon';
    var usedKey = 'shopvivaliz_first_coupon_used';
    var pending = String(localStorage.getItem(pendingKey) || '').toUpperCase();
    var used = String(localStorage.getItem(usedKey) || '').toUpperCase();

    // Google Ads and other paid links can carry the currently advertised
    // coupon as ?cupom=CODE. Persist only a conservative code shape here; the
    // checkout still validates eligibility server-side before any discount is
    // applied, so the URL is never trusted as a pricing source.
    var params = new URLSearchParams(window.location.search || '');
    var deeplinkCoupon = String(params.get('cupom') || '').trim().toUpperCase();
    if (deeplinkCoupon && /^[A-Z0-9_-]{2,30}$/.test(deeplinkCoupon)) {
      localStorage.setItem(pendingKey, deeplinkCoupon);
      pending = deeplinkCoupon;
    }

    if (pending === 'PRIMEIRA10' || pending === 'PRIMEIRA15') {
      localStorage.removeItem(pendingKey);
    }
    if (used === 'PRIMEIRA10' || used === 'PRIMEIRA15') {
      localStorage.removeItem(usedKey);
    }
  } catch (error) {}
})();
