const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'js/catalog.js'), 'utf8');

function clickCatalogBuy(initialCart, { openMiniCart = false } = {}) {
  let savedCart = JSON.stringify(initialCart);
  let miniCartOpens = 0;
  const events = [];
  const signals = [];
  let clickListener = null;
  const product = { sku: 'VEDANTE100', name: 'Vedante 100cm', price: 28, olist_product_id: '100' };
  const button = {
    dataset: {},
    innerHTML: 'Comprar agora',
    classList: { add() {}, remove() {} },
    getAttribute(name) { return name === 'data-product' ? encodeURIComponent(JSON.stringify(product)) : null; },
    addEventListener(name, listener) { if (name === 'click') clickListener = listener; }
  };
  const grid = { querySelectorAll(selector) { return selector === '[data-product]' ? [button] : []; } };
  const input = { value: '', addEventListener() {} };
  const form = { addEventListener() {} };
  const status = { textContent: '' };
  const page = {};
  const document = {
    body: { dataset: { catalogPreservePath: '1' } },
    querySelector(selector) {
      return ({ '.catalog-page': page, '.catalog-search': form })[selector] || null;
    },
    querySelectorAll(selector) { return selector === '[data-product]' ? [button] : []; },
    getElementById(id) {
      return ({ 'product-grid': grid, 'catalog-status': status, 'catalog-search': input })[id] || null;
    }
  };
  const window = {
    location: { href: '' },
    decodeURIComponent,
    dispatchEvent(event) { events.push(event); }
  };
  if (openMiniCart) window.openMiniCart = () => { miniCartOpens += 1; };
  vm.runInNewContext(source, {
    document, window,
    localStorage: {
      getItem(key) { assert.equal(key, 'shopvivaliz_cart'); return savedCart; },
      setItem(key, value) { assert.equal(key, 'shopvivaliz_cart'); savedCart = value; }
    },
    CustomEvent: function (type, init) { this.type = type; this.detail = init.detail; },
    URLSearchParams, fetch: (url, options) => { signals.push({ url, options }); return Promise.resolve({ ok: true }); },
    setTimeout() {}
  }, { filename: 'js/catalog.js' });
  assert.equal(button.dataset.bound, '1');
  assert.equal(typeof clickListener, 'function');
  clickListener({ preventDefault() {} });
  return { cart: JSON.parse(savedCart), destination: window.location.href, events, miniCartOpens, signals };
}

test('catalog Comprar agora adds a product once and immediately opens checkout', () => {
  const result = clickCatalogBuy([]);
  assert.equal(result.destination, '/checkout');
  assert.equal(result.cart.length, 1);
  assert.equal(result.cart[0].sku, 'VEDANTE100');
  assert.equal(result.cart[0].quantity, 1);
  assert.equal(result.cart[0].price, 28);
  assert.equal(result.events.length, 1);
  assert.equal(result.events[0].type, 'shopvivaliz:add_to_cart');
});

test('catalog Comprar agora preserves existing items and bypasses minicart if present', () => {
  const other = { sku: 'OTHER', price: 9, quantity: 1 };
  const result = clickCatalogBuy([other, { sku: 'VEDANTE100', price: 28, quantity: 2 }], { openMiniCart: true });
  assert.equal(result.destination, '/checkout');
  assert.equal(result.miniCartOpens, 0);
  assert.equal(result.cart.length, 2);
  assert.deepEqual(result.cart[0], other);
  assert.equal(result.cart[1].quantity, 3);
  assert.equal(result.cart[1].price, 28);
  assert.equal(result.events.length, 1);
});

test('catalog purchase intent signal survives immediate checkout navigation', () => {
  const result = clickCatalogBuy([]);
  assert.equal(result.signals.length, 1);
  assert.equal(result.signals[0].url, '/api/catalog/signal.php');
  assert.equal(result.signals[0].options.method, 'POST');
  assert.equal(JSON.parse(result.signals[0].options.body).event, 'cart_add');
  assert.equal(result.signals[0].options.keepalive, true);
});
