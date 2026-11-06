#!/usr/bin/env node
'use strict';

// Exercise the exact pure UI helpers shipped in admin/amazon-margin.php.
// Partial financial data must never be counted as certified or simulated.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const page = fs.readFileSync(path.join(__dirname, '../../admin/amazon-margin.php'), 'utf8');
function sourceOf(name) {
  const line = page.split('\n').find(x => x.startsWith('function ' + name + '(r){'));
  assert.ok(line, name + ' helper must be present in the actual admin page');
  return line;
}
const simSource = page.split('\n').find(x => x.startsWith('function simulateGross('));
assert.ok(simSource, 'financial gross simulator must be present in the real page');
const {state, canSimulate, simulateGross} = vm.runInNewContext(
  sourceOf('state') + '\n' + sourceOf('canSimulate') + '\n' + simSource + '\n({state,canSimulate,simulateGross})'
);
const rows = [
  {status:'confirmed',margin_min_bps:-900,margin_weighted_bps:850,price:35,confirmed_units:1,realized_sale_gross_cents:3500,profit_cents:298},
  {status:'confirmed',margin_min_bps:800,margin_weighted_bps:1800,price:60,confirmed_units:2,realized_sale_gross_cents:12000,profit_cents:2160},
  {status:'confirmed',margin_min_bps:3200,margin_weighted_bps:3200,price:70,confirmed_units:1,realized_sale_gross_cents:7000,profit_cents:2240},
  {status:'partial',margin_min_bps:-250,margin_weighted_bps:-100,price:60,confirmed_units:1},
  {status:'partial',margin_min_bps:1200,margin_weighted_bps:2500,price:75,confirmed_units:1},
  {status:'no_confirmed_sales',margin_min_bps:1500,margin_weighted_bps:1800,price:80,confirmed_units:0},
  {status:'confirmed',margin_min_bps:null,margin_weighted_bps:null,price:70,confirmed_units:1},
  {status:'confirmed',margin_min_bps:950,margin_weighted_bps:950,price:0,confirmed_units:1}
];
assert.deepEqual(Array.from(rows, state), ['low','healthy','healthy','pending','pending','pending','pending','low']);
assert.deepEqual(Array.from(rows, canSimulate), [true,true,true,false,false,false,false,false]);
assert.equal(rows.filter(r=>state(r)==='low').length,2);
assert.equal(rows.filter(r=>state(r)==='healthy').length,2);
assert.equal(rows.filter(r=>state(r)==='pending').length,4);
assert.match(page, /Margem atual estimada abaixo de 9%/);
assert.match(page, /Confirmada abaixo de 10%/);
assert.match(page, /Pendente ou parcial/);
assert.match(page, /value="current_low9"/);
assert.match(page, /value="current_pending"/);
assert.match(page, /n-partial-context/);
assert.match(page, /const simOk=canSimulate\(r\)/);
assert.match(page, /if\(!currentSim\|\|!canSimulate\(currentSim\)\)return/);
// Real transaction R9 sold at R$ 112.97 while the listing later rose to R$ 159.
// The simulator MUST anchor to the actual realized gross and profit, never
// multiply current listing price by a historical margin percentage.
const realSale={status:'confirmed',seller_sku:'R9-O6PX-YBXA',price:159,
  realized_sale_gross_cents:11297,confirmed_units:1,profit_cents:587,
  margin_weighted_bps:520};
assert.equal(canSimulate(realSale),true);
const unchanged=simulateGross(realSale,112.97,1400);
assert.ok(unchanged);
assert.equal(Math.round(unchanged.profit*100),587);
assert.equal(Math.round(unchanged.margin*100),520);
assert.ok(Math.abs(simulateGross(realSale,159,1400).profit-5.87)>1);
assert.equal(simulateGross({...realSale,status:'partial'},112.97,1400),null);
assert.equal(simulateGross({...realSale,realized_sale_gross_cents:0},112.97,1400),null);
assert.equal(simulateGross(realSale,0,1400),null);
assert.equal(simulateGross(realSale,112.97,NaN),null);
assert.match(page,/Receita bruta hipotética\/unidade/);
assert.doesNotMatch(page,/old\*oldMargin\/10000/);
console.log('PASS dashboard financial status: certified only, weighted margin, partial pending, safe simulation');
