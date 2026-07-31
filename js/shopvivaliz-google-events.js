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

  function pushOnce(key, eventName, params) {
    var storageKey = 'sv_ga_once_' + key;
    try {
      if (sessionStorage.getItem(storageKey) === '1') return;
      sessionStorage.setItem(storageKey, '1');
    } catch (error) {}
    push(eventName, params);
  }

  function cartItems() {
    return readCart().map(itemFromProduct).filter(function (item) {
      return item.item_id || item.item_name;
    });
  }

  function cartPayload() {
    var items = cartItems();
    return {
      currency: 'BRL',
      value: itemsValue(items),
      items: items
    };
  }

  function trackCartEvent(eventName, onceKey) {
    var payload = cartPayload();
    if (onceKey) pushOnce(onceKey, eventName, payload);
    else push(eventName, payload);
  }

  function parseProductPayload(button) {
    try {
      var raw = String(button.getAttribute('data-product') || '{}');
      var decoder = (typeof window !== 'undefined' && typeof window.decodeURIComponent === 'function')
        ? window.decodeURIComponent.bind(window)
        : function (value) { return value; };
      return JSON.parse(decoder(raw));
    } catch (error) {
      return null;
    }
  }

  function leadChannel(element) {
    if (!element) return '';
    var href = String(element.getAttribute('href') || '').toLowerCase();
    if (href.indexOf('wa.me/') !== -1 || href.indexOf('whatsapp') !== -1) return 'whatsapp';
    if (href.indexOf('mailto:') === 0) return 'email';
    if (href.indexOf('tel:') === 0) return 'telefone';
    return '';
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
        trackCartEvent('begin_checkout', 'begin_checkout_cart');
      }

      var contactLink = event.target && event.target.closest
        ? event.target.closest('a[href*="wa.me"],a[href*="whatsapp"],a[href^="mailto:"],a[href^="tel:"]')
        : null;
      var channel = leadChannel(contactLink);
      if (channel) {
        push('generate_lead', {
          lead_source: 'site',
          lead_channel: channel,
          page_location_path: window.location.pathname
        });
      }
    }, true);
  }

  function bindForms() {
    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!form || !form.matches) return;

      if (form.matches('.catalog-search')) {
        var input = form.querySelector('input[type="search"], input[name="q"], input[name="busca"]');
        var term = input ? String(input.value || '').trim() : '';
        if (term !== '') push('search', { search_term: term });
      }

      if (form.matches('#contact-form,.contact-form,form[action*="contato"],form[action*="contact"]')) {
        push('generate_lead', {
          lead_source: 'site',
          lead_channel: 'formulario',
          page_location_path: window.location.pathname
        });
      }
    }, true);
  }

  function trackPageContext() {
    var path = window.location.pathname.replace(/\/+$/, '') || '/';
    var product = window.ShopVivalizProductContext || null;
    if (product) {
      var item = itemFromProduct(product);
      pushOnce('view_item_' + item.item_id, 'view_item', {
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
        pushOnce('view_item_list_' + path, 'view_item_list', {
          item_list_name: 'Catalogo Vivaliz',
          items: listItems
        });
      }
    }

    if (path === '/carrinho') trackCartEvent('view_cart', 'view_cart');
    if (path === '/checkout') trackCartEvent('begin_checkout', 'begin_checkout_cart');

    // Revenue is deliberately not emitted from browser state. The canonical
    // purchase event is sent server-side only after the payment webhook marks
    // the order approved, preventing boleto/order creation from inflating ROAS.
  }

  window.ShopVivalizGoogleEvents = {
    push: push,
    trackCartEvent: trackCartEvent,
    itemFromProduct: itemFromProduct
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      bindClicks();
      bindForms();
      trackPageContext();
    });
  } else {
    bindClicks();
    bindForms();
    trackPageContext();
  }
})();
