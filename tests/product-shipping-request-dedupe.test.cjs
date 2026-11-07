'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = process.env.PRODUCT_TEST_ROOT || path.resolve(__dirname, '..');
const php = fs.readFileSync(path.join(root, 'produto.php'), 'utf8');
const start = php.indexOf('// Product page freight calculation handler');
const end = php.indexOf("document.querySelectorAll('.buy-button[data-product]')", start);
assert.ok(start > 0 && end > start, 'test real inline product shipping handler');
const source = php.slice(start, end);
function harness() {
  const callbacks = {};
  const requests = [];
  const create = (id, val='') => ({
    value: val, innerHTML: '',
    addEventListener(event, fn) { callbacks[id + ':' + event] = fn; },
  });
  const cep = create('cep');
  const button = create('button');
  const output = create('result');
  const quantity = create('quantity', '1');
  const elements = {'p-frete-btn':button,'p-frete-cep':cep,'p-frete-result':output,'product-qty-input':quantity};
  const document = { getElementById: id=>elements[id]||null };
  const fetch = (url, options)=>{
    let resolve, reject;
    const promise=new Promise((rs, rj)=>{resolve=rs;reject=rj;});
    requests.push({url,options,resolve(data){resolve({json:async()=>data});},reject});
    return promise;
  };
  vm.runInNewContext(source,{document,fetch,product:{sku:'RODOVED100',price:28,olist_product_id:'388356916'}},{filename:'produto.php inline shipping'});
  return {cep,button,output,quantity,requests,
    input(value){cep.value=value;callbacks['cep:input']();},
    click(){callbacks['button:click']();}
  };
}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
test('same CEP auto-calc plus button click shares one pending request',async()=>{
  const h=harness();
  h.input('30130110');
  h.click();
  assert.equal(h.requests.length,1,'duplicate in-flight calls waste API/provider rate limit');
  h.requests[0].resolve({ok:true,shipping_options:[{name:'Express',price:10.53,delivery_time:3}]});
  await tick();
  assert.match(h.output.innerHTML,/R\$ 10,53/);
});
test('stale old CEP response cannot replace newer shipping offer',async()=>{
  const h=harness();
  h.input('30130110');
  h.input('35501236');
  assert.equal(h.requests.length,2,'new destination requires fresh quote');
  h.requests[1].resolve({ok:true,shipping_options:[{name:'New',price:22.5}]});
  await tick();
  h.requests[0].resolve({ok:true,shipping_options:[{name:'Old',price:2.4}]});
  await tick();
  assert.match(h.output.innerHTML,/R\$ 22,50/);
  assert.doesNotMatch(h.output.innerHTML,/R\$ 2,40/);
});
test('failed request can be retried without persistent in-flight block',async()=>{
  const h=harness();
  h.input('30130110');
  h.requests[0].reject(new Error('network'));
  await tick();
  h.click();
  assert.equal(h.requests.length,2);
  h.requests[1].resolve({ok:true,shipping_options:[{name:'Express',price:10.53}]});
  await tick();
  assert.match(h.output.innerHTML,/R\$ 10,53/);
});
