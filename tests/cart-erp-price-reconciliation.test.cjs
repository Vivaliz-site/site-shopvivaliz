'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {test} = require('node:test');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'js/cart-erp-price-reconciliation-v1.js'), 'utf8');

function fixture(options = {}) {
  let items = (options.items || [{sku:'KIT4R-SOPRANO',quantity:1,price:45,image_url:'/img.png'}]).map(x => ({...x}));
  const rawStorage = new Map([['shopvivaliz_cart',JSON.stringify(items)],['shopvivaliz_cart_validated_at','old'],['shopvivaliz_shipping_quote','old']]);
  const events = [];
  const eventShippingSnapshots = [];
  const inserted = [];
  let onReturn;
  const responsePromise = new Promise(resolve => {onReturn = resolve;});
  const parent = {insertBefore(el) {inserted.push(el);}};
  const anchor = {parentNode:parent};
  const document = {
    readyState:'complete',
    hidden:false,
    getElementById(id) {
      if (id === 'cart-items-list' && options.page !== '/checkout') return anchor;
      if (id === 'cart-items' && options.page === '/checkout') return anchor;
      if (id === 'sv-cart-erp-price-sync-status') return inserted[0] || null;
      return null;
    },
    createElement() {return {style:{},hidden:false,setAttribute(){},textContent:''};},
    addEventListener() {}
  };
  const localStorage = {
    getItem(key) { return rawStorage.has(key) ? rawStorage.get(key) : null; },
    setItem(key,value) { rawStorage.set(key, String(value)); if (key === 'shopvivaliz_cart') items=JSON.parse(value);},
    removeItem(key) {rawStorage.delete(key);}
  };
  const window = {
    addEventListener(){},
    dispatchEvent(e) {events.push(e.type);eventShippingSnapshots.push(rawStorage.get('shopvivaliz_shipping_quote'));}
  };
  const ctx = {
    location:{pathname:options.page||'/carrinho'},
    document,localStorage,window,JSON,Date,Number,Object,String,Error,
    CustomEvent:class {constructor(type){this.type=type;}},
    fetch() {return responsePromise;},
    console
  };
  vm.runInNewContext(source,ctx,{filename:'cart-erp-price-reconciliation-v1.js'});
  async function settle() {for (let i=0;i<7;i++) await new Promise(resolve => setImmediate(resolve));}
  return {
    items() {return items;},
    events,
    eventShippingSnapshots,
    storage:rawStorage,
    inserted,
    async respond(data,ok=true) {onReturn({ok,json:async()=>data});await settle();},
    async reject() {onReturn(Promise.reject(new Error('network')));await settle();},
    overwrite(itemsNew) { items=itemsNew;rawStorage.set('shopvivaliz_cart',JSON.stringify(items)); }
  };
}

test('old localStorage prices are replaced by the authoritative Amazon price and cart redraws',async()=>{
  const app=fixture();
  await app.respond({ok:true,items:[{sku:'KIT4R-SOPRANO',quantity:1,unit_price:45.01}]});
  assert.equal(app.items()[0].price,45.01);
  assert.equal(app.items()[0].image_url,'/img.png');
  assert.equal(app.events.includes('shopvivaliz:cart-updated'),true);
  assert.equal(app.storage.has('shopvivaliz_shipping_quote'),false);
  assert.equal(app.eventShippingSnapshots[0],undefined,'Shipping quote must be invalidated before UI render event');
  assert.equal(app.storage.has('shopvivaliz_cart_validated_at'),false);
  assert.match(app.inserted[0].textContent,/atualizados/);
});

test('checkout uses the same canonical update event for stale prices',async()=>{
  const app=fixture({page:'/checkout'});
  await app.respond({ok:true,items:[{sku:'KIT4R-SOPRANO',quantity:1,unit_price:45.01}]});
  assert.equal(app.items()[0].price,45.01);
  assert.ok(app.events.includes('shopvivaliz:cart-updated'));
});

test('unchanged canonical price causes no needless persistent cart write',async()=>{
  const app=fixture({items:[{sku:'KIT4R-SOPRANO',quantity:1,price:45.01}]});
  await app.respond({ok:true,items:[{sku:'KIT4R-SOPRANO',quantity:1,unit_price:45.01}]});
  assert.equal(app.events.length,0);
  assert.equal(app.storage.has('shopvivaliz_shipping_quote'),true);
});

test('ERP/API error preserves the cart and warns instead of fabricating a price',async()=>{
  const app=fixture();
  await app.respond({ok:false,errors:[{sku:'KIT4R-SOPRANO',error:'insufficient_stock'}]},false);
  assert.equal(app.items()[0].price,45);
  assert.equal(app.events.length,0);
  assert.match(app.inserted[0].textContent,/não foi possível/i);
});

test('concurrent quantity change prevents a late response from overwriting user state',async()=>{
  const app=fixture();
  app.overwrite([{sku:'KIT4R-SOPRANO',quantity:2,price:45.00}]);
  await app.respond({ok:true,items:[{sku:'KIT4R-SOPRANO',quantity:1,unit_price:45.01}]});
  assert.equal(app.items()[0].quantity,2);
  assert.equal(app.items()[0].price,45);
  assert.equal(app.events.length,0);
});

test('missing SKU in validated response is not silently assigned a wrong price',async()=>{
  const app=fixture();
  await app.respond({ok:true,items:[{sku:'OTHER',quantity:1,unit_price:3.01}]});
  assert.equal(app.items()[0].price,45);
  assert.equal(app.events.length,0);
  assert.match(app.inserted[0].textContent,/não foi possível/i);
});

test('production cart and checkout templates both load the reconciliation module',()=>{
  for (const file of ['carrinho.php','checkout.php']) {
    const template=fs.readFileSync(path.join(root,file),'utf8');
    assert.match(template,/\/js\/cart-erp-price-reconciliation-v1\.js\?v=20261029/);
  }
  const checkout=fs.readFileSync(path.join(root,'checkout.php'),'utf8');
  assert.match(checkout,/addEventListener\('shopvivaliz:cart-updated', renderCart\)/);
});
