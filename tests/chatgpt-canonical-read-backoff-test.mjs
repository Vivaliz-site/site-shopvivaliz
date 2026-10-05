import assert from 'node:assert/strict';
import vm from 'node:vm';

// Executes the real browser-injected reader. Only HTTP, storage and clock are
// fixtures. Separate contexts model tabs; a shared Map models the same profile.
function browser({ storage = new Map(), clock = { now: 1800000000000 }, status = 429, retryAfter = null, denyStorage = false, pathname = '/c/canonical-backoff-fixture' } = {}) {
  const calls = [];
  let bodyReads = 0;
  const response = { status };
  class Clock extends Date { static now() { return clock.now; } }
  const context = vm.createContext({
    Date: Clock, AbortController, AbortSignal, setTimeout, clearTimeout,
    location: { pathname },
    localStorage: {
      getItem(key) { if (denyStorage) throw Error('storage unavailable'); return storage.get(key) ?? null; },
      setItem(key, value) { if (denyStorage) throw Error('storage unavailable'); storage.set(key, String(value)); },
    },
    async fetch(url) {
      calls.push(url);
      if (url === '/api/auth/session') return { ok: true, async json() { return { account: { id: 'fixture-account' } }; } };
      return {
        ok: response.status === 200, status: response.status,
        headers: { get(name) { return name.toLowerCase() === 'retry-after' ? retryAfter : null; } },
        async json() {
          bodyReads += 1;
          return { current_node: 'node-' + calls.length, mapping: { ['node-' + calls.length]: { children: [], message: {
            author: { role: 'assistant' }, end_turn: true, content: { parts: ['fixture response'] }, status: 'finished_successfully',
          } } } };
        },
      };
    },
  });
  return { calls, response, get bodyReads() { return bodyReads; }, async evaluate(source) { return vm.runInContext(source, context); } };
}

export async function runCanonicalReadBackoffTests({ conversationTurnState }) {
  const cases = [
    ['429 suppresses all auth/history requests in another tab of the same profile', async () => {
      const storage = new Map();
      const a = browser({ storage });
      assert.equal((await conversationTurnState(a)).http_status, 429);
      const b = browser({ storage });
      const result = await conversationTurnState(b);
      assert.equal(result.http_status, 429);
      assert.equal(result.message_status, 'RATE_LIMIT_BACKOFF');
      assert.equal(result.node_id, '');
      assert.equal(b.calls.length, 0, 'a worker reconnect must not reset account backoff');
      assert.equal(a.bodyReads, 0, '429 body content is not needed and must not be parsed');
    }],
    ['expired default backoff resumes a fresh read instead of replaying old proof', async () => {
      const storage = new Map(); const clock = { now: 1800000000000 };
      const a = browser({ storage, clock }); await conversationTurnState(a);
      clock.now += 299000;
      const b = browser({ storage, clock, status: 200 });
      assert.equal((await conversationTurnState(b)).http_status, 429);
      assert.equal(b.calls.length, 0);
      clock.now += 2000;
      assert.equal((await conversationTurnState(b)).http_status, 200);
      assert.equal(b.calls.length, 2);
    }],
    ['numeric Retry-After longer than default is respected', async () => {
      const storage = new Map(); const clock = { now: 1800000000000 };
      await conversationTurnState(browser({ storage, clock, retryAfter: '600' }));
      clock.now += 301000;
      const b = browser({ storage, clock, status: 200 });
      assert.equal((await conversationTurnState(b)).message_status, 'RATE_LIMIT_BACKOFF');
      assert.equal(b.calls.length, 0);
      clock.now += 300000;
      assert.equal((await conversationTurnState(b)).http_status, 200);
    }],
    ['HTTP-date Retry-After is respected', async () => {
      const storage = new Map(); const clock = { now: 1800000000000 };
      const retryAfter = new Date(clock.now + 720000).toUTCString();
      await conversationTurnState(browser({ storage, clock, retryAfter }));
      clock.now += 600000;
      const b = browser({ storage, clock });
      assert.equal((await conversationTurnState(b)).message_status, 'RATE_LIMIT_BACKOFF');
      assert.equal(b.calls.length, 0);
    }],
    ['malformed Retry-After still gets the conservative default pause', async () => {
      const storage = new Map();
      await conversationTurnState(browser({ storage, retryAfter: 'not-a-date' }));
      const b = browser({ storage });
      assert.equal((await conversationTurnState(b)).message_status, 'RATE_LIMIT_BACKOFF');
      assert.equal(b.calls.length, 0);
    }],
    ['numeric 429 Retry-After means 429 seconds, not an HTTP status or year', async () => {
      const storage = new Map(); const clock = { now: 1800000000000 };
      await conversationTurnState(browser({ storage, clock, retryAfter: '429' }));
      clock.now += 301000;
      const b = browser({ storage, clock, status: 200 });
      assert.equal((await conversationTurnState(b)).message_status, 'RATE_LIMIT_BACKOFF');
      assert.equal(b.calls.length, 0);
      clock.now += 129000;
      assert.equal((await conversationTurnState(b)).http_status, 200);
    }],
    ['malformed persisted timestamps do not prevent a fresh canonical read', async () => {
      for (const value of ['broken', 'Infinity', 'NaN']) {
        const storage = new Map([['shopvivaliz.canonical-read-backoff-until.v1', value]]);
        const cdp = browser({ storage, status: 200 });
        assert.equal((await conversationTurnState(cdp)).http_status, 200);
        assert.equal(cdp.calls.length, 2);
      }
    }],
    ['separate browser profiles never share the pause', async () => {
      await conversationTurnState(browser());
      const b = browser({ status: 200 });
      assert.equal((await conversationTurnState(b)).http_status, 200);
      assert.equal(b.calls.length, 2);
    }],
    ['storage denial retains a same-page in-memory pause', async () => {
      const a = browser({ denyStorage: true });
      await conversationTurnState(a);
      assert.equal((await conversationTurnState(a)).message_status, 'RATE_LIMIT_BACKOFF');
      assert.equal(a.calls.length, 2);
    }],
    ['401 is not cached as 429 and can recover after authentication', async () => {
      const a = browser({ status: 401 });
      assert.equal((await conversationTurnState(a)).http_status, 401);
      a.response.status = 200;
      assert.equal((await conversationTurnState(a)).http_status, 200);
    }],
    ['non-conversation pages remain network-free', async () => {
      const a = browser({ pathname: '/' });
      assert.equal((await conversationTurnState(a)).message_status, 'NO_CONVERSATION');
      assert.equal(a.calls.length, 0);
    }],
  ];
  const failed = [];
  for (const [name, run] of cases) {
    try { await run(); console.log('CANONICAL_BACKOFF PASS ' + name); }
    catch (error) { failed.push(name); console.error('CANONICAL_BACKOFF FAIL ' + name + ': ' + error.message); }
  }
  assert.equal(failed.length, 0, failed.join('; '));
  console.log('CANONICAL_READ_BACKOFF_TEST=PASS');
}
if (import.meta.url === `file://${process.argv[1]}`) await runCanonicalReadBackoffTests(await import('../scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs'));
