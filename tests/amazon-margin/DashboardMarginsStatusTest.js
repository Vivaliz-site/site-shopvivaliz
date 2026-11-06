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
const {state, canSimulate} = vm.runInNewContext(
  sourceOf('state') + '\n' + sourceOf('canSimulate') + '\n({state,canSimulate})'
);
const rows = [
  {status:'confirmed',margin_min_bps:-900,margin_weighted_bps:850,price:35,confirmed_units:1},
  {status:'confirmed',margin_min_bps:800,margin_weighted_bps:1800,price:60,confirmed_units:2},
  {status:'confirmed',margin_min_bps:3200,margin_weighted_bps:3200,price:70,confirmed_units:1},
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
assert.match(page, /Margem confirmada abaixo de 10%/);
assert.match(page, /Pendentes \/ parciais/);
assert.match(page, /n-partial-context/);
assert.match(page, /const simOk=canSimulate\(r\)/);
assert.match(page, /if\(!currentSim\|\|!canSimulate\(currentSim\)\)return/);
console.log('PASS dashboard financial status: certified only, weighted margin, partial pending, safe simulation');
