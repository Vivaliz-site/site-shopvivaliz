const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = process.env.TRACKING_TEST_ROOT || path.join(__dirname, '..');
const eventsSource = fs.readFileSync(path.join(root, 'js/shopvivaliz-google-events.js'), 'utf8');
const privacySource = fs.readFileSync(path.join(root, 'js/privacy-consent-v1.js'), 'utf8');
function browser(cookieText, entries = {}, ga4MeasurementId = 'G-1H55K1TZ5D') {
  const cookies = new Map(cookieText.split(';').map(s => s.trim()).filter(Boolean).map(s => {const i=s.indexOf('='); return [s.slice(0,i),s.slice(i+1)];}));
  const saved = new Map(Object.entries(entries));
  const storage = { getItem: key => saved.get(key) || null, setItem: (key,value) => saved.set(key,String(value)), removeItem: key => saved.delete(key) };
  const beacons = [];
  const document = {readyState:'loading',addEventListener(){},getElementById(){return null;},documentElement:{style:{removeProperty(){}}}};
  Object.defineProperty(document,'cookie',{get:()=>Array.from(cookies,([key,value])=>key+'='+value).join('; '),set:value=>{const first=value.split(';')[0];const i=first.indexOf('=');cookies.set(first.slice(0,i),first.slice(i+1));}});
  const window = {ShopVivalizTrackingConfig:{ga4MeasurementId},location:{pathname:'/audit-fixture',search:'',protocol:'https:',href:'https://example.invalid/audit-fixture',reload(){window.reloaded=true;}},gtag(){},crypto:{randomUUID:()=> 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'}};
  const context={window,document,localStorage:storage,sessionStorage:storage,URL,URLSearchParams,Uint8Array,Blob:class {constructor(parts){this.parts=parts;}},navigator:{sendBeacon:(url,blob)=>{assert.equal(url,'/api/analytics/funnel-event.php');beacons.push(JSON.parse(blob.parts.join('')));return true;}}};
  vm.createContext(context);
  vm.runInContext(eventsSource,context);
  return {context,window,saved,beacons,push:()=>window.ShopVivalizGoogleEvents.push('view_item',{currency:'BRL',value:10,items:[{item_id:'TEST',price:10,quantity:1}]})};
}

const baseCookie='sv_privacy_consent=accepted; _ga=GA1.1.123456789.1700000000';
const cases=[
  {name:'Official GS2 session is preserved',cookie:baseCookie+'; _ga_1H55K1TZ5D=GS2.1.s2222222222$o1',want:'123456789.1700000000|2222222222'},
  {name:'Retired stream before official must not change attribution',cookie:baseCookie+'; _ga_QWYPLYMZ9=GS2.1.s1111111111$o1; _ga_1H55K1TZ5D=GS2.1.s2222222222$o1',want:'123456789.1700000000|2222222222'},
  {name:'Unrelated stream cannot supply missing official session',cookie:baseCookie+'; _ga_QWYPLYMZ9=GS2.1.s1111111111$o1',want:'123456789.1700000000'},
  {name:'Legacy GS1 format for official stream remains supported',cookie:baseCookie+'; _ga_1H55K1TZ5D=GS1.1.2222222222.1.0.2222222300.0.0.0',want:'123456789.1700000000|2222222222'},
  {name:'Explicit configured stream is selected',cookie:baseCookie+'; _ga_1H55K1TZ5D=GS2.1.s2222222222$o1; _ga_TEST42=GS2.1.s3333333333$o1',config:'G-TEST42',want:'123456789.1700000000|3333333333'},
  {name:'Malformed official cookie is omitted safely',cookie:baseCookie+'; _ga_1H55K1TZ5D=%E0%A4%A',want:'123456789.1700000000'},
];
let failures=0;
function check(name,fn){try{fn();console.log('PASS: '+name);}catch(error){failures++;console.error('FAIL: '+name+' — '+error.message);}}
for(const fixture of cases)check(fixture.name,()=>{const app=browser(fixture.cookie,{},fixture.config);app.push();assert.equal(app.beacons.length,1);assert.equal(app.beacons[0].client_id,fixture.want);});
check('Stored GA identity is not reused after browser cookies disappear',()=>{const app=browser('sv_privacy_consent=accepted',{'sv_funnel_client_v1':'123456789.1700000000|2222222222'});app.push();assert.equal(app.beacons[0].client_id,'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');});
check('Essential-only choice clears the saved GA identity immediately',()=>{const app=browser(baseCookie,{'sv_funnel_client_v1':'123456789.1700000000|2222222222'});vm.runInContext(privacySource,app.context);app.window.ShopVivalizPrivacy.essentialOnly();assert.equal(app.saved.has('sv_funnel_client_v1'),false);app.push();assert.equal(app.beacons.length,0);});
check('Resetting consent clears the saved GA identity before reloading',()=>{const app=browser(baseCookie,{'sv_funnel_client_v1':'123456789.1700000000|2222222222'});vm.runInContext(privacySource,app.context);app.window.ShopVivalizPrivacy.reset();assert.equal(app.saved.has('sv_funnel_client_v1'),false);assert.equal(app.window.reloaded,true);});
check('First-party non-GA fallback remains valid after browser cookies disappear',()=>{const app=browser('sv_privacy_consent=accepted',{'sv_funnel_client_v1':'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'});app.push();assert.equal(app.beacons[0].client_id,'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb');});

const paymentSource = fs.readFileSync(path.join(root, 'js/checkout-payment-v2.js'), 'utf8');
function paymentBrowser(withGtag) {
  let submitHandlers = 0;
  const dataLayer = [];
  const form = { dataset: {}, addEventListener(type) { if (type === 'submit') submitHandlers++; }, querySelector() { return null; } };
  const paymentDoc = {
    readyState: 'complete',
    querySelector() { return null; },
    getElementById(id) { return id === 'checkout-form' ? form : null; },
  };
  const paymentWindow = { location: { pathname: '/checkout' }, dataLayer };
  if (withGtag) paymentWindow.gtag = (...args) => dataLayer.push(args);
  vm.runInNewContext(paymentSource, {window: paymentWindow, document: paymentDoc, localStorage: {getItem: () => null}}, {timeout: 1000});
  return {window: paymentWindow, dataLayer, submitHandlers};
}
check('Checkout payment wrapper must not bind a second submit handler', () => {
  const ctx = paymentBrowser(true);
  assert.equal(ctx.submitHandlers, 0, 'shopvivaliz-google-events.js is the sole add_payment_info source');
  assert.match(eventsSource, /pushOnce\('add_payment_info_checkout', 'add_payment_info'/);
  ctx.window.triggerGooglePurchaseEvent('SVTEST', 5, []);
  assert.equal(ctx.dataLayer.length, 1, 'gtag must produce exactly one dataLayer entry');
  assert.equal(ctx.dataLayer[0][1], 'order_created');
  assert.equal(ctx.dataLayer.some(item => item[1] === 'purchase'), false);
});
check('Pending Ads wrapper uses only one dataLayer fallback when gtag is absent', () => {
  const ctx = paymentBrowser(false);
  ctx.window.triggerGoogleAdsConversion('SVTEST', 5);
  assert.equal(ctx.dataLayer.length, 1);
  assert.equal(ctx.dataLayer[0].event, 'ads_conversion_pending_payment');
});


// Regression: a form with novalidate can dispatch submit before the checkout
// has accepted the details. Invalid attempts are not payment milestones.
function funnelHarness(pathname, consent) {
  const documentListeners = {};
  const windowListeners = {};
  const windowCaptureListeners = {};
  const beacons = [];
  const gtagEvents = [];
  const local = new Map([['shopvivaliz_cart', JSON.stringify([
    {sku:'TEST-SKU', olist_product_id:'OLIST-123', name:'Test product', price:28, quantity:1}
  ])]]);
  const session = new Map();
  const storage = map => ({
    getItem: key => map.has(key) ? map.get(key) : null,
    setItem: (key, value) => map.set(key, String(value)),
    removeItem: key => map.delete(key)
  });
  const cookies = new Map(consent ? [['sv_privacy_consent','accepted']] : []);
  const form = {
    valid: false,
    matches: selector => selector === '#checkout-form',
    checkValidity() { return this.valid; },
    querySelector: selector => selector.includes('payment_method') ? {value:'mercado_pago'} : null
  };
  const document = {
    readyState: 'loading',
    addEventListener(name, fn) { (documentListeners[name] ||= []).push(fn); },
    getElementById: () => null,
    querySelectorAll: () => [],
    documentElement: {style: {removeProperty(){}}}
  };
  Object.defineProperty(document, 'cookie', {
    get: () => [...cookies].map(([key, value]) => key + '=' + value).join('; '),
    set: value => {
      const [key, val] = value.split(';')[0].split('=');
      cookies.set(key, val || '');
    }
  });
  const window = {
    location: {pathname, search:'', protocol:'https:', href:'https://example.invalid' + pathname},
    crypto: {randomUUID: () => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'},
    decodeURIComponent,
    dataLayer: [],
    gtag: (...args) => gtagEvents.push(args),
    addEventListener(name, fn, capture) {
      ((capture ? windowCaptureListeners : windowListeners)[name] ||= []).push(fn);
    },
    dispatchEvent(evt) {
      (windowCaptureListeners[evt.type] || []).forEach(fn => fn(evt));
      (windowListeners[evt.type] || []).forEach(fn => fn(evt));
    },
    ShopVivalizProductContext: pathname === '/produto' ? {sku:'TEST-SKU',price:28} : null
  };
  const context = {
    document, window,
    localStorage: storage(local), sessionStorage: storage(session),
    URL, URLSearchParams, Uint8Array,
    CustomEvent: class { constructor(type, options) { this.type=type; this.detail=options.detail; } },
    Blob: class { constructor(parts) { this.parts = parts; } },
    navigator: {sendBeacon: (url, blob) => {
      assert.equal(url, '/api/analytics/funnel-event.php');
      beacons.push(JSON.parse(blob.parts.join('')));
      return true;
    }}
  };
  vm.createContext(context);
  vm.runInContext(eventsSource, context);
  (documentListeners.DOMContentLoaded || []).forEach(fn => fn());
  return {
    form, window, context, session, beacons, gtagEvents,
    submit(valid) { form.valid=valid; (documentListeners.submit || []).forEach(fn => fn({target:form})); },
    accept() { vm.runInContext(privacySource, context); window.ShopVivalizPrivacy.acceptAll(); },
    clickBuy() {
      const product = {sku:'TEST-SKU', olist_product_id:'OLIST-123', name:'Test product', price:28};
      const button = {getAttribute(name) { return name==='data-product' ? encodeURIComponent(JSON.stringify(product)) : ''; }};
      const target = {closest(selector) { return selector==='[data-product]' ? button : null; }};
      (documentListeners.click || []).forEach(fn => fn({target}));
    },
    commitCart(quantity, detail={product_id:'OLIST-123'}) {
      local.set('shopvivaliz_cart', JSON.stringify([
        {sku:'TEST-SKU', olist_product_id:'OLIST-123', name:'Test product', price:28, quantity}
      ]));
      window.dispatchEvent({type:'shopvivaliz:add_to_cart', detail});
    },
    clickRemove() {
      const button = {getAttribute: key => key==='data-remove' ? '0' : null};
      const target = {closest: selector => selector==='.btn-remove[data-remove]' ? button : null};
      (documentListeners.click || []).forEach(fn => fn({target}));
    },
    clickMinus() {
      const button = {getAttribute: key => key==='data-idx' ? '0' : (key==='data-delta' ? '-1' : null)};
      const target = {closest: selector => selector==='.qty-btn[data-delta="-1"]' ? button : null};
      (documentListeners.click || []).forEach(fn => fn({target}));
    },
    changeCart(quantity, origin) {
      const items = quantity > 0 ? [{sku:'TEST-SKU', olist_product_id:'OLIST-123', name:'Test product', price:28, quantity}] : [];
      local.set('shopvivaliz_cart', JSON.stringify(items));
      if (origin==='storage') {
        window.addEventListener('storage', () => window.dispatchEvent({type:'shopvivaliz:cart-updated', detail:{items}}));
        window.dispatchEvent({type:'storage', key:'shopvivaliz_cart'});
      } else {
        window.dispatchEvent({type:'shopvivaliz:cart-updated', detail:{items}});
      }
    }
  };
}
check('Invalid checkout submit must not count payment info or consume one-time milestone', () => {
  const app=funnelHarness('/checkout', true);
  app.submit(false);
  assert.equal(app.beacons.filter(x => x.event_name === 'add_payment_info').length, 0);
  app.submit(true);
  assert.equal(app.beacons.filter(x => x.event_name === 'add_payment_info').length, 1);
  app.submit(true);
  assert.equal(app.beacons.filter(x => x.event_name === 'add_payment_info').length, 1);
});
check('Accepting analytics on a product page records one consented first-party view', () => {
  const app=funnelHarness('/produto', false);
  assert.equal(app.beacons.filter(x => x.event_name === 'view_item').length, 0);
  app.accept();
  assert.equal(app.beacons.filter(x => x.event_name === 'view_item').length, 1);
  assert.equal(app.session.get('sv_ga_once_view_item_TEST-SKU'), '1');
});


check('Cart-add analytics fires only after persisted cart quantity grows and exactly once', () => {
  const app=funnelHarness('/catalogo', true);
  app.clickBuy(); // Store quota error / no successful app write.
  assert.equal(app.beacons.filter(x => x.event_name === 'add_to_cart').length, 0,
    'click without successful cart mutation must not count as add_to_cart');
  app.commitCart(4); // Existing quantity 1 => three new units.
  const events=app.gtagEvents.filter(x => x[1] === 'add_to_cart');
  assert.equal(events.length, 1, 'one canonical GA4 event after app event and write');
  assert.equal(events[0][2].items[0].quantity, 3, 'attribution uses actual added quantity');
  assert.equal(events[0][2].value, 84);
  assert.equal(app.beacons.filter(x => x.event_name === 'add_to_cart').length, 1);
  app.commitCart(4); // Duplicate application notification without an additional write.
  assert.equal(app.gtagEvents.filter(x => x[1] === 'add_to_cart').length, 1);
});


check('PDP SKU event honors actual multi-unit cart delta', () => {
  const app=funnelHarness('/produto', true);
  app.commitCart(3, {sku:'TEST-SKU', quantity:2});
  const adds=app.gtagEvents.filter(x => x[1] === 'add_to_cart');
  assert.equal(adds.length, 1);
  assert.equal(adds[0][2].items[0].item_id, 'TEST-SKU');
  assert.equal(adds[0][2].items[0].quantity, 2);
  assert.equal(adds[0][2].value, 56);
});


check('Remove click without committed storage change must NOT fire remove_from_cart', () => {
  const app=funnelHarness('/carrinho', true);
  app.clickRemove();
  assert.equal(app.gtagEvents.filter(e => e[1] === 'remove_from_cart').length, 0);
  assert.equal(app.beacons.filter(e => e.event_name === 'remove_from_cart').length, 0);
});

check('Only committed removal is counted, once per persisted decrease', () => {
  const app=funnelHarness('/carrinho', true);
  app.clickRemove();
  app.changeCart(0);
  let remove=app.gtagEvents.filter(e => e[1] === 'remove_from_cart');
  assert.equal(remove.length, 1);
  assert.equal(remove[0][2].items[0].quantity, 1);
  assert.equal(remove[0][2].value, 28);
  app.changeCart(0); // repeated update, zero delta
  assert.equal(app.gtagEvents.filter(e => e[1] === 'remove_from_cart').length, 1);
  app.changeCart(3); // increment is not a removal
  app.clickMinus();
  app.changeCart(2); // quantity decrement of 1
  remove=app.gtagEvents.filter(e => e[1] === 'remove_from_cart');
  assert.equal(remove.length, 2);
  assert.equal(remove[1][2].items[0].quantity, 1);
  assert.equal(remove[1][2].value, 28);
});

check('Cross-tab storage changes never create duplicate removal attribution', () => {
  const app=funnelHarness('/carrinho', true);
  app.changeCart(0, 'storage');
  assert.equal(app.gtagEvents.filter(e => e[1] === 'remove_from_cart').length, 0);
  app.changeCart(3);
  app.clickMinus();
  app.changeCart(2);
  const remove=app.gtagEvents.filter(e => e[1] === 'remove_from_cart');
  assert.equal(remove.length, 1);
  assert.equal(remove[0][2].items[0].quantity, 1);
});


check('Checkout order completion clears cart WITHOUT user remove_from_cart', () => {
  const app=funnelHarness('/checkout', true);
  app.changeCart(0); // checkout clearCart() calls ShopVivalizCart.clear -> cart-updated
  assert.equal(app.gtagEvents.filter(e => e[1] === 'remove_from_cart').length, 0);
  assert.equal(app.beacons.filter(e => e.event_name === 'remove_from_cart').length, 0);
});

check('Automated cart cleanup is not a remove_from_cart signal', () => {
  const app=funnelHarness('/carrinho', true);
  app.changeCart(0); // automated cleanup without user remove/quantity-minus
  assert.equal(app.gtagEvents.filter(e => e[1] === 'remove_from_cart').length, 0);
});

check('Removal intent expires after event bubble, not during microtask checkpoints', () => {
  // Browser DOM listeners may run microtasks between capture and bubble.
  // Replacing this with Promise.resolve().then() broke a real headed click.
  assert.match(eventsSource, /setTimeout\(expireIntent,\s*0\)/);
});

process.exitCode=failures?1:0;
