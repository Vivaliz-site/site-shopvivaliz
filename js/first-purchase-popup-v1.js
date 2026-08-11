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

    if (pending === 'PRIMEIRA10' || pending === 'PRIMEIRA15') {
      localStorage.removeItem(pendingKey);
    }
    if (used === 'PRIMEIRA10' || used === 'PRIMEIRA15') {
      localStorage.removeItem(usedKey);
    }
  } catch (error) {}
})();
