'use strict';

const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync(require('path').join(__dirname, '..', 'js', 'shipping-request-dedupe-v1.js'), 'utf8');

let resolveFetch;
let networkCalls = 0;
const pendingResponse = new Promise((resolve) => {
  resolveFetch = resolve;
});

const windowObject = {
  location: { pathname: '/checkout' },
  fetch: function () {
    networkCalls += 1;
    return pendingResponse;
  },
};

const context = vm.createContext({
  window: windowObject,
  Response,
  console,
});
vm.runInContext(source, context, { filename: 'shipping-request-dedupe-v1.js' });

const url = '/api/melhorenvio/shipping-check-v2.php';
const init = {
  method: 'POST',
  body: JSON.stringify({ cep: '01001000', items: [{ sku: 'TEST', quantity: 1 }] }),
};

// Reproduz a ordem real do checkout: o primeiro consumidor registra json()
// antes de o blur do CEP gerar a segunda chamada identica ainda em voo.
const firstConsumer = windowObject.fetch(url, init).then((response) => response.json());
const secondConsumer = windowObject.fetch(url, init).then((response) => response.json());

resolveFetch(new Response(JSON.stringify({ ok: true, options: [1, 2, 3, 4, 5] }), {
  status: 200,
  headers: { 'content-type': 'application/json' },
}));

Promise.all([firstConsumer, secondConsumer])
  .then(([first, second]) => {
    if (networkCalls !== 1) {
      throw new Error(`expected 1 network call, got ${networkCalls}`);
    }
    if (!first.ok || !second.ok || first.options.length !== 5 || second.options.length !== 5) {
      throw new Error('both consumers must receive the complete shipping payload');
    }
    console.log('OK: duplicate in-flight consumers receive independent response bodies');
  })
  .catch((error) => {
    console.error('FAIL:', error && error.stack ? error.stack : error);
    process.exitCode = 1;
  });
