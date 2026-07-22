(function () {
  function readCart() {
    try { return JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]'); }
    catch (e) { return []; }
  }
  function updateBadges() {
    var total = readCart().reduce(function (sum, item) { return sum + Number(item.quantity || 1); }, 0);
    ['nav-cart-count', 'mobile-cart-count'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.textContent = total > 0 ? String(total) : '';
      el.style.display = total > 0 ? 'inline-flex' : 'none';
    });
  }
  window.openMiniCart = function () {
    var overlay = document.getElementById('mini-cart-overlay');
    var drawer = document.getElementById('mini-cart-drawer');
    if (overlay) overlay.classList.add('active');
    if (drawer) drawer.classList.add('active');
  };
  window.closeMiniCart = function () {
    var overlay = document.getElementById('mini-cart-overlay');
    var drawer = document.getElementById('mini-cart-drawer');
    if (overlay) overlay.classList.remove('active');
    if (drawer) drawer.classList.remove('active');
  };
  document.addEventListener('DOMContentLoaded', function () {
    updateBadges();
    var close = document.getElementById('mini-cart-close');
    var overlay = document.getElementById('mini-cart-overlay');
    if (close) close.addEventListener('click', window.closeMiniCart);
    if (overlay) overlay.addEventListener('click', window.closeMiniCart);
  });
})();
