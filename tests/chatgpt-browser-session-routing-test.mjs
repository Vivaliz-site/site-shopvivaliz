import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import vm from 'node:vm';

// Only HTTP/CDP transport is substituted. The worker selects the endpoint and
// enforces its actual checkpoint/account/stream guards. Never contact a user.
export async function runBrowserSessionRoutingTests(api, directory) {
  const id = 'account-routing-fixture';
  const conversation = 'bound-corporate-conversation';
  const file = path.join(directory, `${id}.json`);
  const originalFetch = globalThis.fetch;
  const failures = [];
  const write = (extra = {}) => fs.writeFileSync(file, JSON.stringify({
    schema_version: 1, task_id: id, status: 'RUNNING', conversation_id: conversation,
    repository: 'Vivaliz-site/site-shopvivaliz', browser_session: 'atendimento', ...extra,
  }));
  const capture = async (extra = {}, target = conversation) => {
    write(extra);
    const urls = [];
    globalThis.fetch = async url => { urls.push(String(url)); throw Error('isolated_transport_boundary'); };
    const outcome = await api.attemptNudge(id, null, undefined, undefined, target);
    return { urls, outcome };
  };
  const cases = [
    ['corporate checkpoint reaches CDP9556, never personal CDP9555', async () => {
      const result = await capture();
      assert.equal(result.urls[0], 'http://127.0.0.1:9556/json/version');
      assert.ok(result.urls.every(url => !url.includes(':9555/')));
    }],
    ['explicit personal checkpoint preserves CDP9555', async () => {
      assert.equal((await capture({ browser_session: 'fred' })).urls[0], 'http://127.0.0.1:9555/json/version');
    }],
    ['legacy checkpoint preserves existing route', async () => {
      assert.equal((await capture({ browser_session: undefined })).urls[0], 'http://127.0.0.1:9555/json/version');
    }],
    ['invalid account fails before any browser request', async () => {
      const result = await capture({ browser_session: 'another-account' });
      assert.equal(result.urls.length, 0); assert.equal(result.outcome.sent, false);
    }],
    ['bound account requires an explicit conversation', async () => {
      const result = await capture({ conversation_id: undefined }, '');
      assert.equal(result.urls.length, 0); assert.equal(result.outcome.sent, false);
    }],
    ['queue cannot replace the bound conversation', async () => {
      const result = await capture({}, 'wrong-conversation');
      assert.equal(result.urls.length, 0); assert.equal(result.outcome.sent, false);
    }],
    ['terminal corporate checkpoint cannot perform browser recovery', async () => {
      const result = await capture({ status: 'CONCLUIDO' });
      assert.equal(result.urls.length, 0); assert.equal(result.outcome.sent, false);
    }],
    ['actual account mismatch prevents navigation/reload/input', async () => {
      write(); const evaluated = [];
      const cdp = { close() {}, async evaluate(source) {
        evaluated.push(source);
        if (source.includes('continuity-browser-account-match')) return vm.runInNewContext(source, { location: { pathname: '/c/' + conversation }, AbortSignal, fetch: async () => ({ ok: true, json: async () => ({ user: { email: 'wrong-account@example.invalid' } }) }) });
        throw Error('no other evaluation is allowed after mismatch');
      } };
      const result = await api.attemptNudge(id, async () => cdp, undefined, undefined, conversation);
      assert.equal(result.sent, false);
      assert.match(result.detail, /browser session account mismatch/);
      assert.equal(evaluated.length, 1);
    }],
    ['server-side active stream is deferred even when DOM Stop is absent', async () => {
      write(); const evaluated = [];
      const cdp = { close() {}, async evaluate(source) {
        evaluated.push(source);
        if (source.includes('continuity-browser-account-match')) return vm.runInNewContext(source, { location: { pathname: '/c/' + conversation }, AbortSignal, fetch: async () => ({ ok: true, json: async () => ({ user: { email: 'atendimento@shopvivaliz.com.br' } }) }) });
        if (source.includes('/stream_status')) return { http_status: 200, status: 'IS_STREAMING' };
        throw Error('active stream must prevent all recovery effects');
      } };
      const result = await api.attemptNudge(id, async () => cdp, undefined, undefined, conversation);
      assert.equal(result.result_status, 'STALLED_NOT_CONFIRMED');
      assert.equal(result.sent, false);
      assert.match(result.detail, /active|unconfirmed/);
      assert.equal(evaluated.length, 2);
    }],
    ['overlapping async sessions do not leak routes or alter legacy default', async () => {
      write(); const personal = path.join(directory, 'account-routing-personal.json');
      fs.writeFileSync(personal, JSON.stringify({ schema_version: 1, task_id: 'account-routing-personal', status: 'RUNNING', browser_session: 'fred', conversation_id: 'personal-conversation' }));
      const urls = []; globalThis.fetch = async url => { urls.push(String(url)); throw Error('isolated_transport_boundary'); };
      const connect = async () => { await new Promise(resolve => setTimeout(resolve, 5)); return api.Cdp.connectToChatgptTab(); };
      await Promise.all([
        api.attemptNudge(id, connect, undefined, undefined, conversation),
        api.attemptNudge('account-routing-personal', connect, undefined, undefined, 'personal-conversation'),
      ]);
      assert.deepEqual(urls.sort(), ['http://127.0.0.1:9555/json/version', 'http://127.0.0.1:9556/json/version']);
      urls.length = 0;
      await api.attemptNudge('legacy-no-checkpoint');
      assert.equal(urls[0], 'http://127.0.0.1:9555/json/version');
      fs.unlinkSync(personal);
    }],
  ];
  try {
    for (const [name, check] of cases) {
      try { await check(); console.log(`BROWSER_SESSION_CASE PASS ${name}`); }
      catch (error) { failures.push(name); console.error(`BROWSER_SESSION_CASE FAIL ${name}: ${error.message}`); }
    }
  } finally { globalThis.fetch = originalFetch; fs.rmSync(file, { force: true }); }
  assert.equal(failures.length, 0, `browser-session failures: ${failures.join('; ')}`);
  console.log('BROWSER_SESSION_ROUTING_TEST=PASS');
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'browser-session-routing-'));
  process.env.SHOPVIVALIZ_AGENT_TASK_STATE_DIR = directory;
  try { await runBrowserSessionRoutingTests(await import('../scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs'), directory); }
  finally { fs.rmSync(directory, { recursive: true, force: true }); }
}
