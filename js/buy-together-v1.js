(function () {
  'use strict';

  var OFFER_KEY = 'shopvivaliz_buy_together_v1';
  var CART_KEY = 'shopvivaliz_cart';
  var COUPON_KEY = 'shopvivaliz_coupon';
  var SHIPPING_KEY = 'shopvivaliz_shipping_quote';
  var DISCOUNT_PERCENT = 3;

  function money(value) {
    var number = Math.max(0, Number(value) || 0);
    return 'R$ ' + number.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }

  function parseMoney(text) {
    var normalized = String(text || '')
      .replace(/[^0-9,.]/g, '')
      .replace(/\./g, '')
      .replace(',', '.');
    var value = Number(normalized);
    return Number.isFinite(value) ? value : 0;
  }

  function readJson(key, fallback) {
    try {
      var value = JSON.parse(localStorage.getItem(key) || 'null');
      return value === null ? fallback : value;
    } catch (error) {
      return fallback;
    }
  }

  function writeJson(key, value) {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch (error) {}
  }

  function getCart() {
    if (window.ShopVivalizCart && typeof window.ShopVivalizCart.get === 'function') {
      try { return window.ShopVivalizCart.get() || []; } catch (error) {}
    }
    var cart = readJson(CART_KEY, []);
    return Array.isArray(cart) ? cart : [];
  }

  function setCart(items) {
    if (window.ShopVivalizCart && typeof window.ShopVivalizCart.set === 'function') {
      try {
        window.ShopVivalizCart.set(items);
        return;
      } catch (error) {}
    }
    writeJson(CART_KEY, items);
    try { localStorage.setItem('shopvivaliz_cart_updated_at', String(Date.now())); } catch (error) {}
    window.dispatchEvent(new CustomEvent('shopvivaliz:cart-updated', { detail: { items: items } }));
  }

  function addOne(items, product) {
    var sku = String(product.sku || '').trim();
    if (!sku) return items;
    var existing = items.find(function (item) { return String(item.sku || '').toLowerCase() === sku.toLowerCase(); });
    if (existing) {
      existing.quantity = Math.max(1, Number(existing.quantity) || 1) + 1;
      return items;
    }
    items.push({
      sku: sku,
      name: String(product.name || sku),
      image_url: String(product.image_url || '/images/logo-vivaliz-square-v2.png'),
      price: Math.max(0, Number(product.price) || 0),
      quantity: 1,
      olist_product_id: String(product.olist_product_id || product.id || '')
    });
    return items;
  }

  function findPairFromLegacySection(section) {
    var cta = section.querySelector('a.buy-button[data-sku], a[data-sku][data-product-id]');
    if (!cta) return null;

    var sku = String(cta.getAttribute('data-sku') || '').trim();
    if (!sku) return null;

    var strongs = Array.prototype.slice.call(section.querySelectorAll('strong'));
    var images = Array.prototype.slice.call(section.querySelectorAll('img'));
    var moneySpans = Array.prototype.slice.call(section.querySelectorAll('span')).filter(function (node) {
      return /R\$\s*[0-9]/.test(node.textContent || '');
    });

    var name = strongs.length > 1 ? String(strongs[1].textContent || '').trim() : sku;
    var image = images.length > 1 ? String(images[1].getAttribute('src') || '') : '';
    var price = moneySpans.length > 1 ? parseMoney(moneySpans[1].textContent) : 0;

    if (!price) return null;
    return {
      sku: sku,
      name: name || sku,
      image_url: image,
      price: price,
      olist_product_id: String(cta.getAttribute('data-product-id') || '')
    };
  }

  function activateProductOffer() {
    var section = document.querySelector('.sv-compre-junto');
    var main = window.ShopVivalizProductContext || null;
    if (!section || !main || !main.sku || !(Number(main.price) > 0)) return;

    var pair = findPairFromLegacySection(section);
    if (!pair || String(pair.sku).toLowerCase() === String(main.sku).toLowerCase()) return;

    var heading = section.querySelector('h2');
    if (heading) heading.textContent = 'Compre junto e ganhe 3% OFF nos 2 itens';

    var regularTotal = Number(main.price) + Number(pair.price);
    var mainWithDiscount = Number(main.price) * 0.97;
    var pairWithDiscount = Number(pair.price) * 0.97;
    var discountedTotal = regularTotal * 0.97;

    var totalBox = section.querySelector('div[style*="text-align: right"]') || section;
    var existingSavings = section.querySelector('[data-sv-buy-together-savings]');
    if (!existingSavings) {
      var savings = document.createElement('div');
      savings.setAttribute('data-sv-buy-together-savings', '1');
      savings.style.marginTop = '8px';
      savings.style.fontSize = '13px';
      savings.style.lineHeight = '1.45';
      savings.style.color = '#166534';
      savings.style.fontWeight = '700';
      savings.innerHTML = 'Com o combo: <strong>' + money(mainWithDiscount) + '</strong> + <strong>' + money(pairWithDiscount) + '</strong><br>Total com 3% OFF: <strong>' + money(discountedTotal) + '</strong> · economia de ' + money(regularTotal - discountedTotal);
      totalBox.appendChild(savings);
    }

    var cta = section.querySelector('a.buy-button[data-sku], a[data-sku][data-product-id]');
    if (!cta) return;
    cta.textContent = 'Adicionar os 2 com 3% OFF';
    cta.setAttribute('href', '#');
    cta.setAttribute('role', 'button');
    cta.setAttribute('aria-label', 'Adicionar os dois produtos ao carrinho com 3% de desconto em cada item');

    cta.addEventListener('click', function (event) {
      event.preventDefault();
      var items = getCart().slice();
      addOne(items, {
        sku: main.sku,
        name: main.name || main.sku,
        image_url: (document.getElementById('main-product-image') || {}).src || '',
        price: Number(main.price) || 0,
        olist_product_id: main.olist_product_id || ''
      });
      addOne(items, pair);
      setCart(items);

      var offer = {
        type: 'buy_together',
        skus: [String(main.sku), String(pair.sku)],
        percent: DISCOUNT_PERCENT,
        source: 'product_page',
        created_at: new Date().toISOString()
      };
      writeJson(OFFER_KEY, offer);
      try { localStorage.removeItem('shopvivaliz_pending_payment'); } catch (error) {}

      [main, pair].forEach(function (product) {
        window.dispatchEvent(new CustomEvent('shopvivaliz:add_to_cart', {
          detail: { product_id: String(product.olist_product_id || product.sku || '') }
        }));
      });
      window.location.assign('/carrinho');
    }, { once: false });
  }

  function validCheckoutOffer(cart, offer) {
    if (!offer || offer.type !== 'buy_together' || !Array.isArray(offer.skus) || offer.skus.length !== 2) return null;
    var wanted = offer.skus.map(function (sku) { return String(sku || '').trim().toLowerCase(); }).filter(Boolean);
    if (wanted.length !== 2 || wanted[0] === wanted[1]) return null;
    var bySku = {};
    cart.forEach(function (item) { bySku[String(item.sku || '').trim().toLowerCase()] = item; });
    if (!bySku[wanted[0]] || !bySku[wanted[1]]) return null;
    return { wanted: wanted, bySku: bySku };
  }

  function checkoutBundleDiscount(cart, offer) {
    var valid = validCheckoutOffer(cart, offer);
    if (!valid) return 0;
    var eligibleSubtotal = valid.wanted.reduce(function (sum, sku) {
      var item = valid.bySku[sku];
      return sum + (Math.max(0, Number(item.price) || 0) * Math.max(1, Number(item.quantity) || 1));
    }, 0);
    return Math.round(eligibleSubtotal * DISCOUNT_PERCENT) / 100;
  }

  function activateCheckoutOffer() {
    var form = document.getElementById('checkout-form');
    var totals = document.querySelector('.summary-totals');
    if (!form || !totals) return;

    function sync() {
      var cart = getCart();
      var offer = readJson(OFFER_KEY, null);
      var valid = validCheckoutOffer(cart, offer);
      if (!valid) {
        try { localStorage.removeItem(OFFER_KEY); } catch (error) {}
        offer = null;
      }

      var input = form.querySelector('input[name="buy_together_offer"]');
      if (!input) {
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'buy_together_offer';
        form.appendChild(input);
      }
      input.value = offer ? JSON.stringify(offer) : '';

      var row = document.getElementById('buy-together-discount-row');
      if (!row) {
        row = document.createElement('div');
        row.id = 'buy-together-discount-row';
        row.className = 'summary-row';
        row.innerHTML = '<span>Compre junto (3% nos 2 itens)</span><strong id="buy-together-discount-value"></strong>';
        var subtotalRow = totals.querySelector('.summary-row');
        if (subtotalRow && subtotalRow.nextSibling) totals.insertBefore(row, subtotalRow.nextSibling);
        else totals.appendChild(row);
      }

      var discount = checkoutBundleDiscount(cart, offer);
      row.hidden = discount <= 0;
      var value = document.getElementById('buy-together-discount-value');
      if (value) value.textContent = discount > 0 ? '- ' + money(discount) : '';

      if (discount > 0) {
        var rawSubtotal = cart.reduce(function (sum, item) {
          return sum + (Math.max(0, Number(item.price) || 0) * Math.max(1, Number(item.quantity) || 1));
        }, 0);
        var coupon = readJson(COUPON_KEY, null);
        var couponAmount = coupon ? Math.max(0, Number(coupon.amount) || 0) : 0;
        var shipping = readJson(SHIPPING_KEY, null);
        var shippingTotal = shipping ? Math.max(0, Number(shipping.total) || 0) : 0;
        var finalTotal = Math.max(0, rawSubtotal - discount - couponAmount + shippingTotal);
        var totalEl = document.getElementById('cart-total');
        if (totalEl) totalEl.textContent = money(finalTotal);
      }
    }

    sync();
    window.addEventListener('shopvivaliz:cart-updated', sync);
    window.addEventListener('storage', function (event) {
      if ([OFFER_KEY, CART_KEY, COUPON_KEY, SHIPPING_KEY].indexOf(event.key) !== -1) sync();
    });
    var cartItems = document.getElementById('cart-items');
    if (cartItems && window.MutationObserver) {
      new MutationObserver(function () { setTimeout(sync, 0); }).observe(cartItems, { childList: true, subtree: true });
    }
    document.addEventListener('click', function (event) {
      var target = event.target;
      if (target && (target.id === 'coupon-apply-btn' || target.id === 'coupon-remove-btn' || target.classList.contains('qty-btn') || target.classList.contains('btn-remove'))) {
        setTimeout(sync, 100);
      }
    });
  }

  function boot() {
    var path = window.location.pathname || '/';
    if (path.indexOf('/produto') === 0) activateProductOffer();
    if (path === '/checkout' || path === '/checkout/') activateCheckoutOffer();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
