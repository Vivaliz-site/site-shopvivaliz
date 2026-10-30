'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '..', 'js/catalog.js'), 'utf8');

function harness() {
  const requests = [];
  const listeners = {};
  let pager = null;
  const input = { value: '', addEventListener(name, fn) { listeners[name] = fn; } };
  const grid = {
    innerHTML: '',
    querySelectorAll() { return []; },
    insertAdjacentElement(_where, element) { pager = element; }
  };
  const status = { textContent: '' };
  const count = { textContent: '' };
  const document = {
    body: { dataset: { catalogPreservePath: '1' } },
    querySelector(selector) {
      if (selector === '.catalog-page') return {};
      if (selector === '.catalog-search') return { addEventListener() {} };
      return null;
    },
    querySelectorAll() { return []; },
    getElementById(id) {
      if (id === 'product-grid') return grid;
      if (id === 'catalog-status') return status;
      if (id === 'catalog-search') return input;
      if (id === 'products-count') return count;
      if (id === 'catalog-grid-pager') return pager;
      return null;
    },
    createElement() { return { id: '', style: {}, innerHTML: '' }; }
  };
  const window = {
    location: { search: '' },
    decodeURIComponent,
    dispatchEvent() {},
    scrollTo() {}
  };
  vm.runInNewContext(source, {
    document, window, URLSearchParams,
    AbortController,
    localStorage: { getItem() { return '[]'; } },
    fetch(url, options) {
      return new Promise((resolve, reject) => {
        requests.push({ url, options, resolve, reject });
      });
    },
    setTimeout(callback) { callback(); return 1; },
    clearTimeout() {}
  }, { filename: 'js/catalog.js' });
  return {
    input, grid, status, requests,
    search(term) { input.value = term; listeners.input(); }
  };
}

function product(name, stock, price = 25) {
  return {
    name, sku: name.toUpperCase().replace(/\\s+/g, '-'),
    price, stock, olist_product_id: '99',
    image_url: 'https://example.invalid/product.jpg',
    category: 'Utilidades', slug: name.toLowerCase().replace(/\\s+/g, '-'),
    images: []
  };
}

async function resolve(request, rows) {
  request.resolve({
    ok: true,
    json: async () => ({ ok: true, products: rows, total: rows.length, total_pages: 1 })
  });
  await new Promise(done => setImmediate(done));
  await new Promise(done => setImmediate(done));
}

test('filtered catalog requests sellable stock only and never offers purchase for zero stock', async () => {
  const ui = harness();
  ui.search('vedante');
  assert.match(ui.requests[0].url, /(?:\\?|&)available=1(?:&|$)/);
  await resolve(ui.requests[0], [product('Vedante Esgotado', 0, 49)]);
  assert.match(ui.grid.innerHTML, /Esgotado/);
  assert.match(ui.grid.innerHTML, /is-out-of-stock/);
  assert.doesNotMatch(ui.grid.innerHTML, /class="buy-button"/);
});

test('older search responses cannot replace latest query results', async () => {
  const ui = harness();
  ui.search('vaso');
  ui.search('vedante');
  assert.equal(ui.requests.length, 2);
  await resolve(ui.requests[1], [product('Vedante Atual', 3)]);
  assert.match(ui.grid.innerHTML, /Vedante Atual/);
  await resolve(ui.requests[0], [product('Vaso Antigo', 2)]);
  assert.match(ui.grid.innerHTML, /Vedante Atual/);
  assert.doesNotMatch(ui.grid.innerHTML, /Vaso Antigo/);
});

test('products with proven positive stock remain purchasable', async () => {
  const ui = harness();
  ui.search('vedante');
  await resolve(ui.requests[0], [product('Vedante Disponivel', 9)]);
  assert.match(ui.grid.innerHTML, /Vedante Disponivel/);
  assert.match(ui.grid.innerHTML, /class="buy-button"/);
  assert.doesNotMatch(ui.grid.innerHTML, /out-of-stock-badge/);
});

test('missing stock fails closed rather than creating a purchase path', async () => {
  const ui = harness();
  ui.search('vedante');
  const row = product('Vedante Estoque Desconhecido', 1);
  delete row.stock;
  await resolve(ui.requests[0], [row]);
  assert.doesNotMatch(ui.grid.innerHTML, /class="buy-button"/);
  assert.match(ui.grid.innerHTML, /Esgotado/);
});

test('an older failed response cannot erase the successful latest search', async () => {
  const ui = harness();
  ui.search('vaso');
  ui.search('vedante');
  await resolve(ui.requests[1], [product('Vedante Atual', 3)]);
  ui.requests[0].reject(new Error('old timeout'));
  await new Promise(done => setImmediate(done));
  assert.match(ui.grid.innerHTML, /Vedante Atual/);
  assert.doesNotMatch(ui.status.textContent, /Não conseguimos/);
});
