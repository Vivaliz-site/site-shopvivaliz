import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

// Evaluate the actual expression embedded in the production guardian. No
// browser, account, network or credentials are used by these synthetic probes.
const guardian = readFileSync('scripts/chatgpt-continuity/chatgpt-browser-guardian.sh', 'utf8');
const declaration = 'const probeChatgptSessionState = async candidate => candidate.evaluate(';
const start = guardian.indexOf(declaration);
assert.ok(start !== -1);
const tick = String.fromCharCode(96);
const from = guardian.indexOf(tick, start + declaration.length) + 1;
const until = guardian.indexOf(tick + ');', from);
assert.ok(from > 0 && until > from);
const expression = vm.runInNewContext(tick + guardian.slice(from, until) + tick);

async function state({ status = 200, session = {}, body = '', pathname = '/', composer = false, emailField = false } = {}) {
  const context = {
    fetch: async () => ({
      status, ok: status >= 200 && status < 300,
      json: async () => session,
    }),
    AbortSignal: { timeout: () => ({}) },
    location: { pathname },
    document: {
      body: { innerText: body },
      querySelector: selector => selector === 'input[type=email]' && emailField ? {} : null,
      querySelectorAll: selector => selector === '[contenteditable]' && composer ? [{ getAttribute: () => 'true' }] : [],
    },
  };
  return await vm.runInNewContext(expression, context);
}

test('exact Dev account authenticates without needing an access token', async () => {
  assert.equal(await state({ session: { user: { email: ' Dev@ShopVivaliz.com.br ' } } }), 'AUTHENTICATED');
});
test('other corporate and personal accounts fail closed', async () => {
  assert.equal(await state({ session: { user: { email: 'atendimento@shopvivaliz.com.br' } } }), 'IDENTITY_MISMATCH');
  assert.equal(await state({ session: { user: { email: 'someone@example.net' } } }), 'IDENTITY_MISMATCH');
});
test('account id without verified email is never authenticated', async () => {
  assert.equal(await state({ session: { account: { id: 'synthetic-account-id' } } }), 'UNKNOWN');
});
test('composer or profile shell never substitutes for verified email', async () => {
  assert.equal(await state({ session: {}, composer: true }), 'UNKNOWN');
});
test('real 401 and visible login state are negative', async () => {
  assert.equal(await state({ status: 401 }), 'LOGGED_OUT');
  assert.equal(await state({ session: {}, body: 'Log in or sign up' }), 'LOGGED_OUT');
});

test('unauthenticated root landing with email, log in and sign up is LOGGED_OUT', async () => {
  assert.equal(await state({ session: {}, pathname: '/', body: 'Welcome to ChatGPT Log in Sign up', emailField: true }), 'LOGGED_OUT');
});
test('loading root and partial shell without decisive login evidence remain UNKNOWN', async () => {
  assert.equal(await state({ session: {}, pathname: '/', body: 'Log in Sign up', emailField: false }), 'UNKNOWN');
  assert.equal(await state({ session: {}, pathname: '/', body: 'Log in Sign up', emailField: true, composer: true }), 'UNKNOWN');
  assert.equal(await state({ session: {}, pathname: '/c/fixture', body: 'Log in Sign up', emailField: true }), 'UNKNOWN');
});
