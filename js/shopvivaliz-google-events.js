(function () {
  'use strict';

  function readCart() {
    try {
      var parsed = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]');
      return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
      return [];
    }
  }

  function number(value) {
    var parsed = Number(value || 0);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function itemFromProduct(product) {
    product = product || {};
    return {
      item_id: String(product.sku || product.item_id || product.olist_product_id || ''),
      item_name: String(product.name || product.item_name || 'Produto Vivaliz'),
      item_brand: String(product.brand || 'Vivaliz'),
      item_category: String(product.category || ''),
      price: number(product.price),
      quantity: Math.max(1, parseInt(product.quantity || 1, 10) || 1)
    };
  }

  function itemsValue(items) {
    return items.reduce(function (total, item) {
      return total + number(item.price) * Math.max(1, number(item.quantity) || 1);
    }, 0);
  }

  function push(eventName, params) {
    params = params || {};
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push(Object.assign({ event: eventName }, params));
    if (typeof window.gtag === 'function') {
      window.gtag('event', eventName, params);
    }
  }

  function trackCartEvent(eventName) {
    var items = readCart().map(itemFromProduct).filter(function (item) {
      return item.item_id || item.item_name;
    });
    push(eventName, {
      currency: 'BRL',
      value: itemsValue(items),
      items: items
    });
  }

  function parseProductPayload(button) {
    try {
      return JSON.parse(decodeURIComponent(button.getAttribute('data-product') || '{}'));
    } catch (error) {
      return null;
    }
  }

  function bindClicks() {
    document.addEventListener('click', function (event) {
      var button = event.target && event.target.closest ? event.target.closest('[data-product]') : null;
      if (button) {
        var product = parseProductPayload(button);
        if (product) {
          var item = itemFromProduct(product);
          push('add_to_cart', {
            currency: 'BRL',
            value: number(item.price) * item.quantity,
            items: [item]
          });
        }
      }

      var buyNow = event.target && event.target.closest ? event.target.closest('#buy-now,.main-buy-button') : null;
      if (buyNow && !button && window.ShopVivalizProductContext) {
        var contextItem = itemFromProduct(window.ShopVivalizProductContext);
        push('add_to_cart', {
          currency: 'BRL',
          value: number(contextItem.price) * contextItem.quantity,
          items: [contextItem]
        });
      }

      var checkout = event.target && event.target.closest ? event.target.closest('#btn-checkout,.btn-checkout') : null;
      if (checkout) {
        trackCartEvent('begin_checkout');
      }
    }, true);
  }

  function bindSearches() {
    document.addEventListener('submit', function (event) {
      var form = event.target && event.target.matches && event.target.matches('.catalog-search') ? event.target : null;
      if (!form) return;
      var input = form.querySelector('input[type="search"], input[name="q"], input[name="busca"]');
      var term = input ? String(input.value || '').trim() : '';
      if (term !== '') {
        push('search', { search_term: term });
      }
    }, true);
  }

  function trackPageContext() {
    var path = window.location.pathname.replace(/\/+$/, '') || '/';
    var product = window.ShopVivalizProductContext || null;
    if (product) {
      var item = itemFromProduct(product);
      push('view_item', {
        currency: 'BRL',
        value: number(item.price),
        items: [item]
      });
    }

    if (path === '/catalogo' || path === '/produtos') {
      var listItems = Array.prototype.slice.call(document.querySelectorAll('[data-product]'), 0, 20)
        .map(parseProductPayload)
        .filter(Boolean)
        .map(itemFromProduct);
      if (listItems.length) {
        push('view_item_list', {
          item_list_name: 'Catalogo Vivaliz',
          items: listItems
        });
      }
    }

    if (path === '/carrinho') {
      trackCartEvent('view_cart');
    }

    if (path === '/checkout') {
      trackCartEvent('begin_checkout');
    }

    var purchase = window.ShopVivalizPurchaseContext || null;
    if (purchase && purchase.transaction_id) {
      push('purchase', {
        transaction_id: String(purchase.transaction_id),
        currency: 'BRL',
        value: number(purchase.value),
        items: Array.isArray(purchase.items) ? purchase.items.map(itemFromProduct) : []
      });
    }
  }

  window.ShopVivalizGoogleEvents = {
    push: push,
    trackCartEvent: trackCartEvent,
    itemFromProduct: itemFromProduct
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      bindClicks();
      bindSearches();
      trackPageContext();
    });
  } else {
    bindClicks();
    bindSearches();
    trackPageContext();
  }
})();
