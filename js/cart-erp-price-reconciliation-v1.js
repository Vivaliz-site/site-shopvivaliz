/* Refresh prices cached in the shopper's browser from the authoritative server.
 * The Olist/Tiny Amazon price list is reconciled by the backend; never trust
 * localStorage's previously saved price for cart / checkout display.
 */
(function () {
  'use strict';
  if (!/^\/(carrinho|checkout)\/?$/.test(location.pathname)) return;

  var lastAttempt = 0;
  var inFlight = false;
  function cart() {
    try {
      var stored = window.ShopVivalizCart && typeof window.ShopVivalizCart.get === 'function'
        ? window.ShopVivalizCart.get()
        : JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]');
      return Array.isArray(stored) ? stored : [];
    } catch (error) {
      return [];
    }
  }
  function cartSignature(items) {
    return JSON.stringify(items.map(function (row) {
      return [String(row.sku || '').trim().toLowerCase(), Number(row.quantity) || 1];
    }));
  }
  function status(message, error) {
    var el = document.getElementById('sv-cart-erp-price-sync-status');
    if (!el && message) {
      var anchor = document.getElementById('cart-items-list') || document.getElementById('cart-items');
      if (!anchor || !anchor.parentNode) return;
      el = document.createElement('p');
      el.id = 'sv-cart-erp-price-sync-status';
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      el.style.fontSize = '13px';
      el.style.margin = '8px 0';
      anchor.parentNode.insertBefore(el, anchor);
    }
    if (el) {
      el.textContent = message;
      el.style.color = error ? '#b42318' : '#176644';
      el.hidden = !message;
    }
  }
  function persist(items) {
    // Invalidate dependent totals BEFORE dispatching a cart-updated event:
    // the checkout listener immediately renders shipping and summary values.
    localStorage.removeItem('shopvivaliz_cart_validated_at');
    localStorage.removeItem('shopvivaliz_shipping_quote');
    if (window.ShopVivalizCart && typeof window.ShopVivalizCart.set === 'function') {
      window.ShopVivalizCart.set(items);
    } else {
      localStorage.setItem('shopvivaliz_cart', JSON.stringify(items));
      localStorage.setItem('shopvivaliz_cart_updated_at', String(Date.now()));
      window.dispatchEvent(new CustomEvent('shopvivaliz:cart-updated', {detail: {items: items}}));
    }
  }
  function revalidate() {
    var now = Date.now();
    if (inFlight || now - lastAttempt < 5000) return;
    var start = cart();
    if (!start.length) { status('', false); return; }
    lastAttempt = now;
    inFlight = true;
    var signature = cartSignature(start);
    fetch('/api/cart/validate.php', {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
      body: JSON.stringify({items: start.map(function (row) {
        return {sku: row.sku, quantity: row.quantity || 1};
      })})
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok || !data || data.ok !== true || !Array.isArray(data.items)) {
          throw new Error('cart_validation_failed');
        }
        return data;
      });
    }).then(function (validated) {
      // A user might have changed quantities while the request was running.
      var current = cart();
      if (cartSignature(current) !== signature) return;
      var trusted = {};
      validated.items.forEach(function (item) {
        var key = String(item.sku || '').trim().toLowerCase();
        var price = Number(item.unit_price);
        if (key && Number.isFinite(price) && price > 0) trusted[key] = price;
      });
      var updated = current.map(function (item) {
        var key = String(item.sku || '').trim().toLowerCase();
        if (!Object.prototype.hasOwnProperty.call(trusted, key)) {
          throw new Error('cart_product_missing_from_server');
        }
        var clone = Object.assign({}, item);
        clone.price = trusted[key];
        return clone;
      });
      var changed = updated.some(function (item, i) {
        return Number(current[i].price || 0).toFixed(2) !== item.price.toFixed(2);
      });
      if (changed) {
        persist(updated);
        // Cart and checkout pages redraw from this same event.
        status('Preços atualizados conforme o catálogo da loja.', false);
      } else {
        status('', false);
      }
    }).catch(function () {
      // Never replace a price with an unverified API value.
      status('Não foi possível conferir os preços agora. O valor será validado novamente antes da compra.', true);
    }).finally(function () {
      inFlight = false;
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', revalidate, {once: true});
  } else {
    revalidate();
  }
  window.addEventListener('pageshow', function (event) {
    if (event.persisted) revalidate();
  });
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && Date.now() - lastAttempt >= 60000) revalidate();
  });
})();
