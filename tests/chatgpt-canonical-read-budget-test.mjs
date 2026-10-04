import assert from 'node:assert/strict';
import vm from 'node:vm';
import { createHash } from 'node:crypto';

// Execute the real injected browser program. Only HTTP and the minimal editor
// surface are fixtures: no account, browser, message or provider is contacted.
function browserFixture({ pathname = '/c/read-budget-thread', sessionDelay = 0, readDelay = 0, status = 200 } = {}) {
  const state = { calls: [], aborted: 0, timerDelays: [], clicks: 0, text: '' };
  const delay = (ms, signal) => new Promise((resolve, reject) => {
    const cleanup = () => signal?.removeEventListener('abort', abort);
    const timer = setTimeout(() => { cleanup(); resolve(); }, ms);
    const abort = () => { clearTimeout(timer); cleanup(); state.aborted += 1; reject(new DOMException('fixture timeout', 'AbortError')); };
    if (signal?.aborted) abort(); else signal?.addEventListener('abort', abort, { once: true });
  });
  const composer = { disabled: false, focus() {}, getAttribute(name) { return name === 'contenteditable' ? 'true' : null; } };
  const button = { disabled: false, getAttribute() { return null; }, click() { state.clicks += 1; } };
  const context = vm.createContext({
    location: { pathname }, AbortController, AbortSignal, Date, DOMException,
    setTimeout(fn, ms) { state.timerDelays.push(ms); return setTimeout(fn, ms); }, clearTimeout,
    document: {
      body: null,
      querySelector(selector) { return selector.includes('prompt-textarea') ? composer : selector.includes('send-button') ? button : null; },
      execCommand(_command, _show, value) { state.text = value; return true; },
    },
    async fetch(url, options = {}) {
      state.calls.push(url);
      const session = url === '/api/auth/session';
      await delay(session ? sessionDelay : readDelay, options.signal);
      const code = session ? 200 : status;
      return { ok: code >= 200 && code < 300, status: code, async json() {
        if (session) return { account: { id: 'fixture-account' } };
        if (url.endsWith('/stream_status')) return { status: 'COMPLETE' };
        return { current_node: 'assistant-fixture', mapping: { 'assistant-fixture': { children: [], message: {
          author: { role: 'assistant' }, end_turn: true, status: 'finished_successfully', content: { parts: ['fixture completed response'] },
        } } } };
      } };
    },
  });
  return { state, async evaluate(expression) { return vm.runInContext(expression, context); } };
}

export async function runCanonicalReadBudgetTests({ conversationTurnState, conversationStreamStatus, sendContinueMessage }) {
  const cases = [
    ['slow canonical read is not confused with a 5s stream-status timeout', async () => {
      const cdp = browserFixture({ readDelay: 6100 });
      const result = await conversationTurnState(cdp);
      assert.equal(result.http_status, 200, 'real 6.1s conversation response needs its own bounded budget');
      assert.equal(result.node_id, 'assistant-fixture');
      assert.equal(cdp.state.aborted, 0);
    }],
    ['authentication and conversation share one total deadline', async () => {
      const cdp = browserFixture({ sessionDelay: 60, readDelay: 80 });
      const result = await conversationTurnState(cdp, 100);
      assert.equal(result.http_status, 0, 'session time must consume the total budget');
      assert.equal(result.message_status, 'FETCH_TIMEOUT');
      assert.equal(cdp.state.aborted, 1, 'the underlying request must be aborted, not abandoned');
    }],
    ['canonical reads and stream state support the existing uc route', async () => {
      const cdp = browserFixture({ pathname: '/uc/read-budget-thread' });
      assert.equal((await conversationTurnState(cdp)).http_status, 200);
      assert.equal((await conversationStreamStatus(cdp)).status, 'COMPLETE');
      assert.ok(cdp.state.calls.includes('/backend-api/conversation/read-budget-thread'));
    }],
    ['uc conversation identity is verified before the existing send path', async () => {
      const cdp = browserFixture({ pathname: '/uc/read-budget-thread' });
      const fingerprint = createHash('sha256').update('/uc/read-budget-thread').digest('hex');
      assert.equal(await sendContinueMessage(cdp, fingerprint), true);
      assert.equal(cdp.state.clicks, 1);
      assert.equal(await sendContinueMessage(cdp, 'wrong-conversation'), false);
      assert.equal(cdp.state.clicks, 1, 'a different conversation must never receive a send');
    }],
    ['rate limits remain explicit failures rather than completion', async () => {
      const result = await conversationTurnState(browserFixture({ status: 429 }));
      assert.equal(result.http_status, 429);
      assert.equal(result.node_id, '');
      assert.equal(result.end_turn, null);
    }],
    ['explicit budgets cannot outlive the 15s CDP transport', async () => {
      const cdp = browserFixture();
      assert.equal((await conversationTurnState(cdp, 999999)).http_status, 200);
      assert.ok(cdp.state.timerDelays.every(ms => ms <= 12000), 'canonical request timeout must remain below CDP timeout');
    }],
    ['non-conversation routes do not trigger account requests', async () => {
      const cdp = browserFixture({ pathname: '/settings' });
      assert.equal((await conversationTurnState(cdp)).message_status, 'NO_CONVERSATION');
      assert.equal((await conversationStreamStatus(cdp)).status, 'NO_CONVERSATION');
      assert.equal(cdp.state.calls.length, 0);
    }],
  ];
  const failures = [];
  for (const [name, check] of cases) {
    try { await check(); console.log(`CANONICAL_READ_CASE PASS ${name}`); }
    catch (error) { failures.push(name); console.error(`CANONICAL_READ_CASE FAIL ${name}: ${error.message}`); }
  }
  assert.equal(failures.length, 0, `canonical read failures: ${failures.join('; ')}`);
  console.log('CANONICAL_READ_BUDGET_TEST=PASS');
}

if (import.meta.url === `file://${process.argv[1]}`) {
  await runCanonicalReadBudgetTests(await import('../scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs'));
}
