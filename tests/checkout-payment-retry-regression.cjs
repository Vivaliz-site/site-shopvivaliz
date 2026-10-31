'use strict';
// Runs the real checkout submit handler and published total hardener with
// deterministic network stubs. No server calls, orders or payments are made.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const { webcrypto } = require('node:crypto');
const root = process.env.CHECKOUT_TEST_ROOT || path.join(__dirname, '..');
const checkout = fs.readFileSync(path.join(root, 'checkout.php'), 'utf8');
const hardener = fs.readFileSync(path.join(root, 'includes/checkout-output-hardening.php'), 'utf8');

const helperStart = checkout.indexOf('    function pendingKey(');
const helperEnd = checkout.indexOf('    window.svNewCheckoutSubmit = true;', helperStart);
const submitStart = checkout.indexOf("document.getElementById('checkout-form').addEventListener('submit', async function (e)", helperEnd);
const submitEnd = checkout.indexOf('    /* Submit legado:', submitStart);
assert.ok(helperStart >= 0 && helperEnd > helperStart && submitStart > helperEnd && submitEnd > submitStart, 'canonical checkout hooks must exist');
let handlerSource = checkout.slice(submitStart, submitEnd);
const replacementMatch = hardener.match(/"(var shippingQuote = getShippingQuote\(\);\\n[^"]*window\.ShopVivalizLastOrder = order;)"/);
assert.ok(replacementMatch, 'published authoritative total hardener must exist');
const totalReplacement = JSON.parse('"' + replacementMatch[1] + '"');
const rawTotalStart = handlerSource.indexOf('            var shippingQuote = getShippingQuote();');
const rawTotalEnd = handlerSource.indexOf('            var name = fd.get(', rawTotalStart);
assert.ok(rawTotalStart > 0 && rawTotalEnd > rawTotalStart, 'checkout total block must exist');
handlerSource = handlerSource.slice(0, rawTotalStart) + '            ' + totalReplacement + '\n' + handlerSource.slice(rawTotalEnd);

async function scenario(change, options = {}) {
  const memory = new Map();
  const orders = [];
  const payments = [];
  const redirects = [];
  const button = { disabled: false, textContent: '' };
  const status = { textContent: '', className: '' };
  const form = { checkValidity() { return true; }, reportValidity() {}, addEventListener(name, handler) { if (name === 'submit') this.handler = handler; } };
  const fields = {
    customer_name: 'Synthetic Fixture', customer_email: 'fixture@example.invalid',
    customer_phone: '(00) 00000-0000', cep: '01310-100', address: 'Fixture Street',
    street_number: '1', complement: 'Unit A', neighborhood: 'Fixture', city: 'Fixture City',
    state: 'SP', cpf: '00000000000', payment_method: 'mercado_pago',
  };
  let coupon = { code: 'TEST10', amount: 4.22 };
  const items = [{ sku: 'FIXTURE-A', quantity: 1, price: 15.47 }, { sku: 'FIXTURE-B', quantity: 1, price: 28 }];
  const shipping = { total: 13.58, option: { id: 'fixture-shipping' }, cep: '01310100', quote_id: 'fixture-quote', expires_at: 2000000000 };
  const context = {
    console, URLSearchParams, TextEncoder,
    HAS_PAYMENT_GATEWAY: true, WPP_NUM: '',
    window: { crypto: options.cryptoUnavailable ? undefined : webcrypto, location: { search: '', assign(url) { redirects.push(url); } }, deviceId: '' },
    document: { getElementById(id) { return id === 'checkout-form' ? form : id === 'submit-btn' ? button : status; }, querySelector() { return null; } },
    localStorage: { getItem(k) { return memory.has(k) ? memory.get(k) : null; }, setItem(k, v) { memory.set(k, String(v)); }, removeItem(k) { memory.delete(k); } },
    FormData: class { forEach(fn) { for (const [k, v] of Object.entries(fields)) fn(v, k); } get(k) { return fields[k] ?? null; } },
    getCart: () => structuredClone(items), getShippingQuote: () => structuredClone(shipping),
    shippingLabelFromQuote: () => 'Fixture Shipping', getCoupon: () => coupon,
    clearCart() {}, clearShippingQuote() {}, clearCoupon() {}, renderCart() {}, fmtMoney: v => String(v),
    async fetch(url, request) {
      const payload = JSON.parse(request.body);
      if (url === '/api/orders/create.php') {
        orders.push(payload);
        const subtotal = items.reduce((sum, item) => sum + item.price * item.quantity, 0);
        const total = options.invalidTotal ? 'invalid' : Math.round((subtotal - 1.30 - coupon.amount + shipping.total) * 100) / 100;
        return { ok: true, async json() { return {
          ok: true, order_number: 'SV-FIXTURE-' + orders.length,
          payment_session_token: 'fixture-session-' + orders.length,
          total, shipping_total: shipping.total,
          customer: { email: 'must-not-be-persisted@example.invalid' },
        }; } };
      }
      if (url === '/api/mercadopago/create-preference.php' || url === '/api/infinitepay/create-link.php') {
        payments.push({ url, payload });
        if (payments.length === 1) return { ok: false, async json() { return { ok: false, message: 'Fixture temporary gateway failure' }; } };
        return { ok: true, async json() { return { ok: true, checkout_url: 'https://example.invalid/fixture' }; } };
      }
      throw new Error('Unexpected endpoint in offline test');
    },
  };
  vm.createContext(context);
  vm.runInContext(checkout.slice(helperStart, helperEnd) + '\n' + handlerSource, context);
  await form.handler.call(form, { preventDefault() {} });
  if (options.cryptoUnavailable || options.invalidTotal) return { orders, payments, memory, status, button };
  assert.equal(orders.length, 1, 'first attempt creates one fixture order');
  assert.equal(payments.length, 1, 'first attempt reaches the simulated gateway failure');
  assert.match(status.textContent, /Fixture temporary gateway failure/);
  const pending = JSON.parse(memory.get('shopvivaliz_pending_payment'));
  if (change) change({ fields, items, shipping, memory, context, setCoupon(value) { coupon = value; } });
  await form.handler.call(form, { preventDefault() {} });
  return { orders, payments, pending, redirects, status, lastOrder: context.window.ShopVivalizLastOrder };
}

(async () => {
  let failures = 0;
  async function check(name, fn) {
    try { await fn(); console.log('PASS: ' + name); }
    catch (error) { failures++; console.error('FAIL: ' + name + ' — ' + error.message); }
  }
  await check('Unchanged gateway retry preserves order, token and authoritative totals', async () => {
    const result = await scenario();
    assert.equal(result.orders.length, 1, 'retry must not create a second order or reserve stock again');
    assert.equal(result.payments.length, 2, 'retry must reach the payment provider boundary');
    assert.deepEqual(result.payments[1].payload, result.payments[0].payload);
    assert.equal(result.pending.total, 51.53);
    assert.equal(result.pending.shipping_total, 13.58);
    assert.equal(result.lastOrder.total, 51.53);
    assert.equal(result.redirects.length, 1);
  });
  const changes = [
    ['coupon', app => app.setCoupon({ code: 'OTHER', amount: 1.00 })],
    ['delivery number', app => { app.fields.street_number = '2'; }],
    ['customer email', app => { app.fields.customer_email = 'changed@example.invalid'; }],
    ['customer name', app => { app.fields.customer_name = 'Changed Fixture'; }],
    ['payer document', app => { app.fields.cpf = '11111111111'; }],
    ['phone', app => { app.fields.customer_phone = '11111111111'; }],
    ['signed shipping quote', app => { app.shipping.quote_id = 'new-fixture-quote'; app.shipping.total = 14.58; }],
    ['item price', app => { app.items[0].price = 16.47; }],
    ['payment method', app => { app.fields.payment_method = 'infinitepay'; }],
  ];
  for (const [name, change] of changes) await check('Changed ' + name + ' requires a newly validated order', async () => {
    const result = await scenario(change);
    assert.equal(result.orders.length, 2);
    assert.equal(result.payments.length, 2);
    assert.equal(result.payments[1].payload.order_number, 'SV-FIXTURE-2');
    assert.equal(result.payments[1].payload.payment_session_token, 'fixture-session-2');
    assert.equal(Object.hasOwn(result.payments[1].payload, 'total'), false, 'gateway must receive a session, never a client price');
  });
  await check('Tracking-only changes reuse the existing order', async () => {
    const result = await scenario(app => { app.memory.set('sv_gclid', 'synthetic-new-click'); app.memory.set('sv_funnel_client_v1', 'synthetic-new-identity'); });
    assert.equal(result.orders.length, 1);
    assert.equal(result.payments.length, 2);
  });
  await check('Formatting-only customer changes reuse the existing order', async () => {
    const result = await scenario(app => { app.fields.customer_email = ' FIXTURE@EXAMPLE.INVALID '; app.fields.customer_phone = '00000000000'; app.fields.cep = '01310100'; });
    assert.equal(result.orders.length, 1);
    assert.equal(result.payments.length, 2);
  });
  await check('Pending state contains only required fields and no plaintext customer identity', async () => {
    const result = await scenario();
    assert.deepEqual(Object.keys(result.pending).sort(), ['key', 'method', 'order_number', 'payment_session_token', 'shipping_total', 'total']);
    const stored = JSON.stringify(result.pending);
    for (const value of ['fixture@example.invalid', 'Fixture Street', 'Synthetic Fixture', 'must-not-be-persisted']) assert.equal(stored.includes(value), false);
  });
  await check('Unavailable WebCrypto fails safely before order creation', async () => {
    const result = await scenario(null, { cryptoUnavailable: true });
    assert.equal(result.orders.length, 0);
    assert.equal(result.payments.length, 0);
    assert.equal(result.memory.has('shopvivaliz_pending_payment'), false);
    assert.equal(result.button.disabled, false);
    assert.ok(result.status.textContent.length > 0);
  });
  await check('Invalid authoritative total never reaches payment creation', async () => {
    const result = await scenario(null, { invalidTotal: true });
    assert.equal(result.orders.length, 1);
    assert.equal(result.payments.length, 0);
    assert.match(result.status.textContent, /total inválido/);
  });
  console.log(JSON.stringify({ tests: 15, failures }));
  process.exitCode = failures ? 1 : 0;
})().catch(error => { console.error(error.stack); process.exitCode = 2; });
