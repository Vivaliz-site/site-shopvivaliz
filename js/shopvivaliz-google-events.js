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

  function readShippingQuote() {
    try {
      var parsed = JSON.parse(localStorage.getItem('shopvivaliz_shipping_quote') || 'null');
      if (!parsed || typeof parsed !== 'object') return null;
      var expiresAt = Number(parsed.expires_at || 0);
      var now = expiresAt > 1000000000000 ? Date.now() : Math.floor(Date.now() / 1000);
      if (!expiresAt || expiresAt <= now) {
        localStorage.removeItem('shopvivaliz_shipping_quote');
        return null;
      }
      return parsed;
    } catch (error) {
      localStorage.removeItem('shopvivaliz_shipping_quote');
      return null;
    }
  }

  function number(value) {
    var parsed = Number(value || 0);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function itemFromProduct(product) {
    product = product || {};
    var item = {
      item_id: String(product.sku || product.item_id || product.olist_product_id || ''),
      item_name: String(product.name || product.item_name || 'Produto Vivaliz'),
      price: number(product.price),
      quantity: Math.max(1, parseInt(product.quantity || 1, 10) || 1)
    };
    var brand = String(product.brand || '').trim();
    var category = String(product.category || '').trim();
    if (brand) item.item_brand = brand;
    if (category) item.item_category = category;
    return item;
  }

  function itemsValue(items) {
    return items.reduce(function (total, item) {
      return total + number(item.price) * Math.max(1, number(item.quantity) || 1);
    }, 0);
  }

  function analyticsConsentGranted() {
    try {
      var match = document.cookie.match(/(?:^|;\s*)sv_privacy_consent=([^;]+)/);
      var value = match ? decodeURIComponent(match[1]) : '';
      if (!value) {
        var saved = JSON.parse(localStorage.getItem('shopvivaliz_privacy_consent_v1') || 'null');
        value = saved && saved.value ? saved.value : '';
      }
      return value === 'accepted';
    } catch (error) {
      return false;
    }
  }

  function randomId() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID().toLowerCase();
    }
    var bytes = new Uint8Array(16);
    if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
      window.crypto.getRandomValues(bytes);
      return Array.prototype.map.call(bytes, function (value) {
        return value.toString(16).padStart(2, '0');
      }).join('');
    }
    return String(Date.now()) + Math.random().toString(16).slice(2) + Math.random().toString(16).slice(2);
  }

  function readCookie(name) {
  try {
    var match = String(document.cookie || '').split(';').map(function (part) { return part.trim(); }).find(function (part) { return part.indexOf(name + '=') === 0; });
    return match ? decodeURIComponent(match.slice(name.length + 1)) : '';
  } catch (error) { return ''; }
}

function ga4ClientIdFromCookie() {
  var ga = readCookie('_ga');
  var match = ga.match(/^GA\d+\.\d+\.(\d+)\.(\d+)$/);
  return match ? (match[1] + '.' + match[2]) : '';
}

function ga4SessionIdFromCookies() {
  var config = window.ShopVivalizTrackingConfig || {};
  var measurementId = String(config.ga4MeasurementId || config.ga4Id || 'G-1H55K1TZ5D').trim();
  if (!/^G-[A-Z0-9]+$/i.test(measurementId)) return '';
  // Retired streams can leave cookies behind. Only the configured stream's
  // session belongs to the purchase sent to that measurement ID.
  var value = readCookie('_ga_' + measurementId.slice(2));
  var modern = value.match(/(?:^|[.\$])s(\d+)(?:\$|$)/);
  if (modern) return modern[1];
  var legacy = value.match(/^GS\d+(?:\.\d+)?\.(\d+)(?:\.|$)/);
  return legacy ? legacy[1] : '';
}

function funnelClientId() {
  var key = 'sv_funnel_client_v1';
  try {
    if (analyticsConsentGranted()) {
      var clientId = ga4ClientIdFromCookie();
      if (clientId) {
        var sessionId = ga4SessionIdFromCookies();
        var identity = sessionId ? (clientId + '|' + sessionId) : clientId;
        localStorage.setItem(key, identity);
        return identity;
      }
    }
    var existing = String(localStorage.getItem(key) || '');
    // A stored GA identity can outlive consent or the browser cookies. Reuse
    // it only when freshly recovered above from consented browser cookies.
    if (/^[a-f0-9-]{16,64}$/i.test(existing)) return existing;
    var created = randomId();
    localStorage.setItem(key, created);
    return created;
  } catch (error) { return randomId(); }
}

  function captureGclsAndUtmParams() {
    try {
      var params = new URLSearchParams(window.location.search);
      ['gclid', 'gbraid', 'wbraid', 'dclid'].forEach(function (key) {
      var value = params.get(key);
      if (value) localStorage.setItem('sv_' + key, value);
    });

      var utm_source = params.get('utm_source');
      if (utm_source) localStorage.setItem('sv_utm_source', utm_source);

      var utm_medium = params.get('utm_medium');
      if (utm_medium) localStorage.setItem('sv_utm_medium', utm_medium);

      var utm_campaign = params.get('utm_campaign');
      if (utm_campaign) localStorage.setItem('sv_utm_campaign', utm_campaign);

      var utm_content = params.get('utm_content');
      if (utm_content) localStorage.setItem('sv_utm_content', utm_content);
    } catch (error) {}
  }

  function sendFirstParty(eventName, params) {
    if (!analyticsConsentGranted()) return;
    var allowed = [
      'view_item',
      'view_item_list',
      'add_to_cart',
      'remove_from_cart',
      'view_cart',
      'begin_checkout',
      'add_shipping_info',
      'add_payment_info',
      'generate_lead',
      'search'
    ];
    if (allowed.indexOf(eventName) === -1) return;

    var items = params && Array.isArray(params.items) ? params.items : [];
    var payload = JSON.stringify({
      event_id: randomId(),
      event_name: eventName,
      page_path: window.location.pathname,
      item_count: items.length,
      client_id: funnelClientId()
    });

    try {
      if (navigator.sendBeacon) {
        var blob = new Blob([payload], { type: 'application/json' });
        if (navigator.sendBeacon('/api/analytics/funnel-event.php', blob)) return;
      }
    } catch (error) {}

    try {
      fetch('/api/analytics/funnel-event.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: payload,
        credentials: 'same-origin',
        keepalive: true
      }).catch(function () {});
    } catch (error) {}
  }

  function push(eventName, params) {
    // Revenue has exactly one source of truth: the payment-approved webhook.
    // Nenhum fluxo do navegador pode transformar pedido pendente em receita.
    // O purchase aprovado e emitido pelo webhook via Measurement Protocol.
    if (eventName === 'purchase') return;

    params = params || {};
    window.dataLayer = window.dataLayer || [];

    // gtag() is itself a dataLayer writer. Pushing the object manually and then
    // calling gtag('event', ...) emitted every browser funnel event twice when
    // the Google tag/GTM was present. Prefer the canonical gtag command when it
    // exists; fall back to a raw GTM event object only on pages without gtag.
    if (typeof window.gtag === 'function') {
      window.gtag('event', eventName, params);
    } else {
      window.dataLayer.push(Object.assign({ event: eventName }, params));
    }
    sendFirstParty(eventName, params);
  }

  function checkoutMilestoneSignature(eventName, params) {
    // Tab-wide deduplication must not hide a genuinely changed cart, shipping
    // choice or payment option. Only non-PII ecommerce fields are persisted.
    if (['begin_checkout', 'add_shipping_info', 'add_payment_info'].indexOf(eventName) === -1) {
      return null;
    }
    params = params || {};
    var items = Array.isArray(params.items) ? params.items : [];
    var products = items.map(function (item) {
      return [
        String(item.item_id || ''),
        number(item.price),
        Math.max(1, number(item.quantity) || 1)
      ];
    }).sort(function (a, b) {
      var left = JSON.stringify(a);
      var right = JSON.stringify(b);
      return left < right ? -1 : (left > right ? 1 : 0);
    });
    var signature = {
      currency: String(params.currency || 'BRL'),
      value: number(params.value),
      items: products
    };
    if (eventName === 'add_shipping_info') {
      signature.shipping_tier = String(params.shipping_tier || '');
    }
    if (eventName === 'add_payment_info') {
      signature.payment_type = String(params.payment_type || '');
    }
    return JSON.stringify(signature);
  }

  function pushOnce(key, eventName, params) {
    // Do not burn the one-time milestone before analytics consent exists.
    // Consent can be granted while the visitor is already on this page.
    if (!analyticsConsentGranted()) return;
    var storageKey = 'sv_ga_once_' + key;
    var signature = checkoutMilestoneSignature(eventName, params);
    var marker = signature === null ? '1' : signature;
    try {
      if (sessionStorage.getItem(storageKey) === marker) return;
      sessionStorage.setItem(storageKey, marker);
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

  function shippingTierFromQuote(quote) {
    if (!quote || typeof quote !== 'object') return '';
    if (quote.label) return String(quote.label).trim();
    var option = quote.option && typeof quote.option === 'object' ? quote.option : {};
    return [option.company, option.name].filter(Boolean).join(' - ').trim();
  }

  function trackCheckoutDetails(form) {
    var payload = cartPayload();
    if (!payload.items.length) return;

    var quote = readShippingQuote();
    var shippingTier = shippingTierFromQuote(quote);
    if (quote) {
      var shippingPayload = Object.assign({}, payload);
      if (shippingTier) shippingPayload.shipping_tier = shippingTier;
      pushOnce('add_shipping_info_checkout', 'add_shipping_info', shippingPayload);
    }

    var payment = form && form.querySelector
      ? form.querySelector('[name="payment_method"]:checked')
      : null;
    if (payment && payment.value) {
      pushOnce('add_payment_info_checkout', 'add_payment_info', Object.assign({}, payload, {
        payment_type: String(payment.value)
      }));
    }
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

  function cartQuantities(items) {
    var quantities = Object.create(null);
    items.forEach(function (product) {
      if (!product || typeof product !== 'object') return;
      var key = String(product.sku || product.item_id || product.olist_product_id || '').trim();
      var quantity = Number(product.quantity || 1);
      if (!key || !Number.isFinite(quantity) || quantity <= 0) return;
      quantities[key] = (quantities[key] || 0) + quantity;
    });
    return quantities;
  }

  var previousCartQuantities = cartQuantities(readCart());

  function trackCommittedCartAdd(event) {
    // Cart writers (PDP, catalog and related-product cards) dispatch this
    // synchronous event only AFTER localStorage.setItem succeeded. A click
    // alone is not evidence that the shopping cart changed.
    var items = readCart();
    var nextQuantities = cartQuantities(items);
    var previous = previousCartQuantities;
    previousCartQuantities = nextQuantities;
    var detail = event && event.detail && typeof event.detail === 'object' ? event.detail : {};
    var sku = String(detail.sku || '').trim();
    var productId = String(detail.product_id || '').trim();
    if (!sku && !productId) return;

    var changed = items.filter(function (product) {
      if (!product || typeof product !== 'object') return false;
      var key = String(product.sku || product.item_id || product.olist_product_id || '').trim();
      if (!key || number(nextQuantities[key]) <= number(previous[key])) return false;
      return (sku !== '' && String(product.sku || '') === sku)
        || (productId !== '' && (String(product.olist_product_id || '') === productId
          || String(product.sku || '') === productId));
    });
    // Ambiguous SKUs/ERP references are not grounds to invent a product event.
    if (changed.length !== 1) return;

    var product = changed[0];
    var key = String(product.sku || product.item_id || product.olist_product_id || '').trim();
    var delta = Math.floor(number(nextQuantities[key]) - number(previous[key]));
    if (delta < 1) return;
    var item = itemFromProduct(product);
    item.quantity = delta;
    push('add_to_cart', {
      currency: 'BRL',
      value: number(item.price) * item.quantity,
      items: [item]
    });
  }

  if (typeof window.addEventListener === 'function') {
    window.addEventListener('shopvivaliz:add_to_cart', trackCommittedCartAdd);
  }

  // Only a user-initiated remove / quantity-minus is a removal conversion.
  // Successful checkout clears the cart too, but must never be attributed
  // as remove_from_cart. The click records intent ONLY; cart-updated confirms
  // the resulting persisted delta, so failed storage writes emit no event.
  var removalCartSnapshot = readCart();
  var pendingUserRemoval = null;
  var pendingCrossTabCartStorage = false;

  function captureUserRemovalIntent(event) {
    var target = event && event.target;
    if (!target || typeof target.closest !== 'function') return;
    var button = target.closest('.btn-remove[data-remove]');
    var fullRemove = Boolean(button);
    if (!button) button = target.closest('.qty-btn[data-delta="-1"]');
    if (!button) return;
    var index = parseInt(button.getAttribute(fullRemove ? 'data-remove' : 'data-idx'), 10);
    var cart = readCart();
    if (!Number.isInteger(index) || index < 0 || !cart[index]) return;
    var product = cart[index];
    var key = String(product.sku || product.item_id || product.olist_product_id || '').trim();
    if (!key) return;
    var lineQuantity = Math.floor(number(product.quantity || 1));
    var expectedRemoved = fullRemove ? lineQuantity : (lineQuantity > 1 ? 1 : 0);
    if (expectedRemoved < 1) return;
    var intent = {
      key: key,
      expectedRemoved: expectedRemoved,
      totalBefore: cartQuantities(cart)[key],
      product: product
    };
    pendingUserRemoval = intent;
    // Browser event listener microtasks may run before the cart's bubble
    // handler. Clear intent in the next task rather than before cart.set().
    // A failed write must not authorize an unrelated later cart update.
    var expireIntent = function () {
      if (pendingUserRemoval === intent) pendingUserRemoval = null;
    };
    if (typeof setTimeout === 'function') setTimeout(expireIntent, 0);
    else Promise.resolve().then(expireIntent);
  }
  if (typeof window.addEventListener === 'function') {
    // Capture at the same window before the shared cart-persistence script's
    // bubbling storage handler dispatches a synchronous cart-updated event.
    // This preserves the existing cart writer without altering its protocol.
    window.addEventListener('storage', function (event) {
      if (!event || event.key !== 'shopvivaliz_cart') return;
      pendingCrossTabCartStorage = true;
      Promise.resolve().then(function () { pendingCrossTabCartStorage = false; });
    }, true);
  }

  function trackCommittedCartRemoval(event) {
    var nextCart = readCart();
    var previousCart = removalCartSnapshot;
    removalCartSnapshot = nextCart;
    var intent = pendingUserRemoval;
    pendingUserRemoval = null;
    var detail = event && event.detail && typeof event.detail === 'object' ? event.detail : {};
    // Other-tab changes and checkout/order-completion clears are NOT user
    // removal clicks in this page, even if the persisted cart becomes empty.
    if (detail.origin === 'storage' || pendingCrossTabCartStorage) {
      pendingCrossTabCartStorage = false;
      return;
    }
    if (!intent || !intent.key) return;

    var before = cartQuantities(previousCart);
    var after = cartQuantities(nextCart);
    if (number(before[intent.key]) !== number(intent.totalBefore)) return;
    var removed = Math.floor(number(before[intent.key]) - number(after[intent.key]));
    if (removed < 1 || removed !== intent.expectedRemoved) return;
    var item = itemFromProduct(intent.product);
    item.quantity = removed;
    push('remove_from_cart', {
      currency: 'BRL',
      value: number(item.price) * item.quantity,
      items: [item]
    });
  }

  if (typeof window.addEventListener === 'function') {
    window.addEventListener('shopvivaliz:cart-updated', trackCommittedCartRemoval);
  }

  function bindClicks() {
    document.addEventListener('click', function (event) {
      captureUserRemovalIntent(event);
      // add_to_cart is emitted by the committed cart event below, not by
      // click intent. Cart persistence can fail before the item is added.

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

      if (form.matches('#checkout-form')) {
        // checkout.php uses novalidate and validates inside its async submit
        // handler. The capturing analytics listener runs first, so reject
        // invalid attempts before emitting payment/shipping milestones.
        if (form.checkValidity && !form.checkValidity()) return;
        if (!cartItems().length) return;
        trackCheckoutDetails(form);
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

  // The privacy banner does not reload the page on consent selection.
  // Recover the current page milestone once after a new analytics grant.
  if (typeof window.addEventListener === 'function') {
    window.addEventListener('shopvivaliz:privacy-consent-changed', function (event) {
      if (event && event.detail && event.detail.value === 'accepted') {
        trackPageContext();
      }
    });
  }

  window.ShopVivalizGoogleEvents = {
    push: push,
    trackCartEvent: trackCartEvent,
    trackCheckoutDetails: trackCheckoutDetails,
    itemFromProduct: itemFromProduct
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      captureGclsAndUtmParams();
      bindClicks();
      bindForms();
      trackPageContext();
    });
  } else {
    captureGclsAndUtmParams();
    bindClicks();
    bindForms();
    trackPageContext();
  }
})();
