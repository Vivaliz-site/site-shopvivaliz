'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const sourcePath = path.join(__dirname, '..', 'js/shipping-postcode-session-v1.js');
const source = fs.existsSync(sourcePath) ? fs.readFileSync(sourcePath, 'utf8') : '';
const KEY = 'shopvivaliz_postcode_session_v1';
const NOW = 1800000000000;

function harness({ values = {}, saved = null, storageThrows = false, readyState = 'complete' } = {}) {
  const store = new Map();
  if (saved !== null) store.set(KEY, typeof saved === 'string' ? saved : JSON.stringify(saved));
  const events = [], timers = [], documentListeners = {}, windowListeners = {};
  const inputs = {};
  for (const [id, value] of Object.entries(values)) {
    const listeners = {};
    inputs[id] = {
      id, value, dataset: {},
      addEventListener(type, fn) { (listeners[type] ||= []).push(fn); },
      dispatchEvent(event) {
        events.push({ id, type: event.type, bubbles: event.bubbles });
        for (const fn of listeners[event.type] || []) fn.call(this, event);
        return true;
      }
    };
  }
  const storage = {
    getItem(key) { if (storageThrows) throw new Error('blocked storage'); return store.get(key) ?? null; },
    setItem(key, value) { if (storageThrows) throw new Error('blocked storage'); store.set(key, String(value)); },
    removeItem(key) { if (storageThrows) throw new Error('blocked storage'); store.delete(key); }
  };
  const window = { sessionStorage: storage, addEventListener(type, fn) { windowListeners[type] = fn; } };
  const document = {
    readyState,
    getElementById(id) { return inputs[id] || null; },
    addEventListener(type, fn) { documentListeners[type] = fn; }
  };
  const sandbox = { document, window, Date: { now: () => NOW }, Event: class { constructor(type, opts = {}) { this.type = type; this.bubbles = !!opts.bubbles; } }, setTimeout(fn) { timers.push(fn); } };
  const context = vm.createContext(sandbox);
  const flush = () => { while (timers.length) timers.shift()(); };
  vm.runInContext(source, context, { filename: sourcePath });
  flush();
  return { inputs, events, store, windowListeners, documentListeners, flush,
    initAgain() { vm.runInContext(source, context); flush(); },
    type(id, value) { inputs[id].value = value; inputs[id].dispatchEvent(new sandbox.Event('input', {bubbles: true})); },
    saved() { return JSON.parse(store.get(KEY) || 'null'); }
  };
}

for (const id of ['p-frete-cep', 'frete-cep', 'cep-input']) {
  test(`restores the same-session postcode to empty ${id} and notifies its existing handlers`, () => {
    const h = harness({ values: {[id]: ''}, saved: {cep: '35501236', saved_at: NOW - 5000} });
    assert.equal(h.inputs[id].value, '35501-236');
    assert.deepEqual(h.events, [{id, type: 'input', bubbles: true}]);
  });
}

test('remembers only a normalized postcode and timestamp, not shopper identity or a quote', () => {
  const h = harness({ values: {'p-frete-cep': ''} });
  h.type('p-frete-cep', '35501-236');
  assert.deepEqual(h.saved(), {cep: '35501236', saved_at: NOW});
  assert.deepEqual([...h.store.keys()], [KEY]);
});

test('leaves an existing form or browser-autofilled postcode untouched', () => {
  const h = harness({ values: {'cep-input': '01310-100'}, saved: {cep: '35501236', saved_at: NOW} });
  assert.equal(h.inputs['cep-input'].value, '01310-100');
  assert.deepEqual(h.events, []);
  assert.equal(h.saved().cep, '01310100');
});

test('never overwrites a partially edited postcode', () => {
  const h = harness({ values: {'cep-input': '355'}, saved: {cep: '01310100', saved_at: NOW} });
  assert.equal(h.inputs['cep-input'].value, '355');
  assert.deepEqual(h.events, []);
});

for (const saved of [
  {cep: '35501236', saved_at: NOW - 1800001},
  {cep: '35501236', saved_at: NOW + 1000},
  {cep: '00000000', saved_at: NOW},
  {cep: '123', saved_at: NOW},
  {cep: '35501236'},
  '{broken-json'
]) {
  test(`rejects an expired, invalid or malformed stored value: ${JSON.stringify(saved)}`, () => {
    const h = harness({values: {'cep-input': ''}, saved});
    assert.equal(h.inputs['cep-input'].value, '');
    assert.deepEqual(h.events, []);
    assert.equal(h.store.has(KEY), false);
  });
}

test('clearing or partially changing a postcode clears the old session hint', () => {
  const h = harness({values: {'frete-cep': '35501-236'}});
  assert.equal(h.saved().cep, '35501236');
  h.type('frete-cep', '35501');
  assert.equal(h.saved(), null);
});

test('works with storage disabled, without changing the manual shopping flow', () => {
  assert.doesNotThrow(() => {
    const h = harness({values: {'p-frete-cep': ''}, storageThrows: true});
    h.type('p-frete-cep', '35501-236');
    assert.equal(h.inputs['p-frete-cep'].value, '35501-236');
  });
});

test('waits for page handlers and avoids duplicate initialization', () => {
  const h = harness({values: {'cep-input': ''}, readyState: 'loading', saved: {cep: '35501236', saved_at: NOW}});
  assert.equal(h.inputs['cep-input'].value, '');
  assert.equal(typeof h.documentListeners.DOMContentLoaded, 'function');
  h.documentListeners.DOMContentLoaded();h.flush();h.initAgain();
  assert.equal(h.inputs['cep-input'].value, '35501-236');
  assert.equal(h.events.length, 1);
});

test('wires the helper to all three public shopping pages', () => {
  for (const file of ['produto.php', 'carrinho.php', 'checkout.php']) {
    const page = fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
    assert.match(page, /<script[^>]+src="\/js\/shipping-postcode-session-v1\.js[^>]+defer[^>]*><\/script>/, file);
  }
});

test('asynchronous address lookup cannot steal focus from another field', () => {
  const page = fs.readFileSync(path.join(__dirname, '..', 'checkout.php'), 'utf8');
  assert.match(page, /if\s*\(number\s*&&\s*document\.activeElement\s*===\s*cepInput\)\s*number\.focus\(\)/);
});
