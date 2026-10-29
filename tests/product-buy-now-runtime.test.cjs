const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = process.env.PRODUCT_TEST_ROOT || path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'js/product-conversion-v5.js'), 'utf8');

// Execute the actual external handler: it runs in capture phase and takes
// precedence over the inline product handler. A source-only PHP assertion
// missed the production detour through the cart.
function clickBuyNow(initialCart, quantity) {
    let savedCart = JSON.stringify(initialCart);
    const listeners = [];
    const events = [];
    const buy = { addEventListener(type, fn, capture) {
        if (type === 'click') listeners.push({ fn, capture });
    } };
    const detail = { querySelector(selector) {
        if (selector === '.product-sku-line') return { textContent: 'SKU: RODOVED100' };
        if (selector === 'h1') return { textContent: 'Rodo Vedante 100cm' };
        return null;
    }, getAttribute() { return '100'; } };
    const window = {
        ShopVivalizProductContext: { sku: 'RODOVED100', name: 'Rodo Vedante 100cm', price: 28, olist_product_id: '100' },
        location: { href: '' },
        dispatchEvent(event) { events.push(event); }
    };
    const document = {
        body: { classList: { add() {} } },
        querySelector(selector) { return selector === '.product-detail' ? detail : null; },
        getElementById(id) {
            if (id === 'buy-now') return buy;
            if (id === 'product-qty-input') return { value: String(quantity) };
            return null;
        }
    };
    vm.runInNewContext(source, {
        document, window,
        localStorage: {
            getItem(key) { assert.equal(key, 'shopvivaliz_cart'); return savedCart; },
            setItem(key, value) { assert.equal(key, 'shopvivaliz_cart'); savedCart = value; }
        },
        CustomEvent: function (type, init) { this.type = type; this.detail = init.detail; }
    }, { filename: 'product-conversion-v5.js' });
    let stopped = false;
    const event = { preventDefault() {}, stopImmediatePropagation() { stopped = true; } };
    for (const listener of listeners.filter(x => x.capture).concat(listeners.filter(x => !x.capture))) {
        listener.fn(event);
        if (stopped) break;
    }
    return { cart: JSON.parse(savedCart), destination: window.location.href, events };
}

test('Comprar agora saves the selected quantity and opens checkout directly', () => {
    const result = clickBuyNow([], 3);
    assert.equal(result.cart.length, 1);
    assert.equal(result.cart[0].sku, 'RODOVED100');
    assert.equal(result.cart[0].quantity, 3);
    assert.equal(result.cart[0].price, 28);
    assert.equal(result.events.length, 1);
    assert.equal(result.events[0].type, 'shopvivaliz:add_to_cart');
    assert.equal(result.events[0].detail.quantity, 3);
    assert.equal(result.destination, '/checkout');
});

test('Comprar agora preserves other items and adds once to an existing product', () => {
    const other = { sku: 'OTHER', name: 'Existing item', price: 15.47, quantity: 1 };
    const result = clickBuyNow([other, { sku: 'RODOVED100', price: 28, quantity: 2 }], 3);
    assert.equal(result.cart.length, 2);
    assert.deepEqual(result.cart[0], other);
    assert.equal(result.cart[1].quantity, 5);
    assert.equal(result.cart[1].price, 28);
    assert.equal(result.events.length, 1);
    assert.equal(result.destination, '/checkout');
});
