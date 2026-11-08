const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const html = fs.readFileSync(path.join(__dirname, '..', 'produto.php'), 'utf8');
const start = html.indexOf('// Product page freight calculation handler');
const end = html.indexOf("document.querySelectorAll('.buy-button[data-product]')", start);
assert.ok(start > 0 && end > start, 'product shipping handler must exist');
const section = html.slice(start, end);
function elem(value = '') {
  return {value, innerHTML: '', dataset: {}, events: {},
    addEventListener(type, fn) {(this.events[type] ||= []).push(fn);}
  };
}
function makeHarness(failFirst = false) {
  const btn = elem(), cep = elem(), result = elem(), qty = elem('1');
  const el = {'p-frete-btn': btn, 'p-frete-cep': cep,
    'p-frete-result': result, 'product-qty-input': qty};
  const requests = [];
  const fetch = (url, opts) => {
    assert.equal(url, '/api/melhorenvio/shipping-check-v2.php');
    assert.equal(opts.method, 'POST');
    requests.push(JSON.parse(opts.body));
    const response = failFirst && requests.length === 1
      ? {ok: false, message: 'Temporary quote error'}
      : {ok: true, shipping_options: [
          {company: 'TestCarrier', name: 'Standard', price: 12.61, delivery_time: 3}
        ]};
    return Promise.resolve({json: () => Promise.resolve(response)});
  };
  vm.runInNewContext(section, {
    document: {getElementById: id => el[id] || null},
    product: {sku: 'RODOVED100', price: 28, olist_product_id: '388356916'},
    fetch, Date, console
  });
  const emit = (target, event) => {
    for (const fn of target.events[event] || []) fn({key: '', preventDefault() {}});
  };
  return {btn, cep, result, qty, requests, emit};
}
async function settle() {await new Promise(resolve => setTimeout(resolve, 20));}
async function main() {
  const t = makeHarness();
  t.cep.value = '01310100';
  t.emit(t.cep, 'input');
  await settle();
  assert.equal(t.requests.length, 1, 'valid postcode should calculate automatically');
  assert.match(t.result.innerHTML, /frete-results-list/);
  t.emit(t.btn, 'click');
  await settle();
  assert.equal(t.requests.length, 1, 'same completed quote should not request twice on calculate click');
  t.qty.value = '2';
  t.emit(t.btn, 'click');
  await settle();
  assert.equal(t.requests.length, 2, 'quantity change must recalculate');
  t.cep.value = '22041001';
  t.emit(t.cep, 'input');
  await settle();
  assert.equal(t.requests.length, 3, 'postcode change must recalculate');
  const retry = makeHarness(true);
  retry.cep.value = '01310100';
  retry.emit(retry.cep, 'input');
  await settle();
  assert.equal(retry.requests.length, 1);
  retry.emit(retry.btn, 'click');
  await settle();
  assert.equal(retry.requests.length, 2, 'failed quote must be retryable');
  console.log('PDP_SHIPPING_DEDUPE_GREEN: auto, click, qty, cep, retry');
}
main().catch(err => {console.error('PDP_SHIPPING_DEDUPE_FAIL',err.message);process.exitCode=1;});
