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

function product(name, price = 25) {
  return {
    name, sku: name.toUpperCase().replace(/\\s+/g, '-'),
    price, olist_product_id: '99',
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

test('latest search results must supersede an older response', async () => {
  const ui = harness();
  ui.search('vaso');
  ui.search('vedante');
  assert.equal(ui.requests.length, 2);
  await resolve(ui.requests[1], [product('Vedante Atual')]);
  assert.match(ui.grid.innerHTML, /Vedante Atual/);
  await resolve(ui.requests[0], [product('Vaso Antigo')]);
  assert.match(ui.grid.innerHTML, /Vedante Atual/);
  assert.doesNotMatch(ui.grid.innerHTML, /Vaso Antigo/);
});

test('a new search aborts the prior in-flight request', () => {
  const ui = harness();
  ui.search('vaso');
  const prior = ui.requests[0];
  ui.search('vedante');
  assert.ok(prior.options.signal, 'missing request abort signal');
  assert.equal(prior.options.signal.aborted, true);
});

test('a late failure must not erase newer successful results', async () => {
  const ui = harness();
  ui.search('vaso');
  ui.search('vedante');
  await resolve(ui.requests[1], [product('Vedante Atual')]);
  ui.requests[0].reject(new Error('older search timeout'));
  await new Promise(done => setImmediate(done));
  assert.match(ui.grid.innerHTML, /Vedante Atual/);
  assert.doesNotMatch(ui.status.textContent, /Nao conseguimos|Não conseguimos/);
});
