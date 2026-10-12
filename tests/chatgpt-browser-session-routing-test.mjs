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
    ['corporate checkpoints cannot wake the personal reinforcement monitor', async () => {
      const isolated = fs.mkdtempSync(path.join(os.tmpdir(), 'personal-reinforcement-routing-'));
      const checkpoint = path.join(isolated, 'task.json');
      try {
        fs.writeFileSync(checkpoint, JSON.stringify({ status: 'RUNNING', browser_session: 'atendimento' }));
        assert.equal(api.hasActiveContinuityCheckpoint(isolated), false);
        fs.writeFileSync(checkpoint, JSON.stringify({ status: 'RUNNING', browser_session: 'invalid' }));
        assert.equal(api.hasActiveContinuityCheckpoint(isolated), false);
        fs.writeFileSync(checkpoint, JSON.stringify({ status: 'RUNNING', browser_session: 'fred' }));
        assert.equal(api.hasActiveContinuityCheckpoint(isolated), false);
        fs.writeFileSync(checkpoint, JSON.stringify({ status: 'RUNNING', browser_session: 'fred', conversation_id: 'fred-bound-conversation' }));
        assert.equal(api.hasActiveContinuityCheckpoint(isolated), true);
        fs.writeFileSync(checkpoint, JSON.stringify({ status: 'RUNNING' }));
        assert.equal(api.hasActiveContinuityCheckpoint(isolated), false);
        fs.writeFileSync(checkpoint, JSON.stringify({ status: 'RUNNING', conversation_id: 'legacy-bound-conversation' }));
        assert.equal(api.hasActiveContinuityCheckpoint(isolated), true);
      } finally { fs.rmSync(isolated, { recursive: true, force: true }); }
    }],
    ['corporate checkpoint reaches CDP9556, never personal CDP9555', async () => {
      const result = await capture();
      assert.equal(result.urls[0], 'http://127.0.0.1:9556/json/version');
      assert.ok(result.urls.every(url => !url.includes(':9555/')));
    }],
    ['dev checkpoint reaches CDP9559', async () => {
      assert.equal((await capture({ browser_session: 'dev' })).urls[0], 'http://127.0.0.1:9559/json/version');
    }],
    ['only an exact Dev auxiliary opt-in routes a bound Dev checkpoint to CDP9557', async () => {
      const previous = process.env.SHOPVIVALIZ_CONTINUITY_DEV_ADOPTED_CDP;
      try {
        process.env.SHOPVIVALIZ_CONTINUITY_DEV_ADOPTED_CDP = '9557';
        const dev = await capture({ browser_session: 'dev' });
        assert.equal(dev.urls[0], 'http://127.0.0.1:9557/json/version');
        const atendimento = await capture({ browser_session: 'atendimento' });
        assert.equal(atendimento.urls[0], 'http://127.0.0.1:9556/json/version');
        assert.ok(atendimento.urls.every(url => !url.includes(':9557/')));
        const noProof = await capture({ browser_session: 'dev', conversation_id: undefined }, '');
        assert.equal(noProof.urls.length, 0, 'unbound legacy checkpoints must never request CDP');
      } finally {
        if (previous === undefined) delete process.env.SHOPVIVALIZ_CONTINUITY_DEV_ADOPTED_CDP;
        else process.env.SHOPVIVALIZ_CONTINUITY_DEV_ADOPTED_CDP = previous;
      }
    }],
    ['Dev auxiliary opt-in rejects arbitrary CDP values before reaching a browser', async () => {
      const previous = process.env.SHOPVIVALIZ_CONTINUITY_DEV_ADOPTED_CDP;
      try {
        for (const forbidden of ['9556', '9558', 'http://127.0.0.1:9557', 'true']) {
          process.env.SHOPVIVALIZ_CONTINUITY_DEV_ADOPTED_CDP = forbidden;
          const result = await capture({ browser_session: 'dev' });
          assert.equal(result.urls.length, 0, 'invalid flag ' + forbidden);
          assert.equal(result.outcome.sent, false);
        }
      } finally {
        if (previous === undefined) delete process.env.SHOPVIVALIZ_CONTINUITY_DEV_ADOPTED_CDP;
        else process.env.SHOPVIVALIZ_CONTINUITY_DEV_ADOPTED_CDP = previous;
      }
    }],
    ['Dev auxiliary session still requires exact Dev account before UI mutation', async () => {
      const previous = process.env.SHOPVIVALIZ_CONTINUITY_DEV_ADOPTED_CDP;
      process.env.SHOPVIVALIZ_CONTINUITY_DEV_ADOPTED_CDP = '9557';
      try {
        write({ browser_session: 'dev' });
        const evaluated = [];
        const fake = { close() {}, async evaluate(source) {
          evaluated.push(source);
          if (source.includes('continuity-browser-account-match')) return vm.runInNewContext(source, {
            location: { pathname: '/c/' + conversation },
            AbortSignal,
            fetch: async () => ({ ok: true, json: async () => ({
              user: { email: 'atendimento@shopvivaliz.com.br' },
            }) }),
          });
          throw Error('wrong Dev identity must block all UI work');
        } };
        const result = await api.attemptNudge(id, async () => fake, undefined, undefined, conversation);
        assert.equal(result.sent, false);
        assert.match(result.detail, /browser session account mismatch/);
        assert.equal(evaluated.length, 1);
      } finally {
        if (previous === undefined) delete process.env.SHOPVIVALIZ_CONTINUITY_DEV_ADOPTED_CDP;
        else process.env.SHOPVIVALIZ_CONTINUITY_DEV_ADOPTED_CDP = previous;
      }
    }],
    ['Dev auxiliary must verify its identity before any neutral-tab selection or navigation', async () => {
      const previous = process.env.SHOPVIVALIZ_CONTINUITY_DEV_ADOPTED_CDP;
      process.env.SHOPVIVALIZ_CONTINUITY_DEV_ADOPTED_CDP = '9557';
      try {
        write({ browser_session: 'dev' });
        const { connectBoundConversationWithReentry } = await import('../scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs');
        const neutral = { type: 'page', url: 'https://chatgpt.com/', webSocketDebuggerUrl: 'ws://neutral-fixture' };
        const events = [];
        const probe = {
          close() {},
          async evaluate() {
            events.push('identity_probe');
            return false; // A different account or missing user.email.
          },
        };
        const outcome = await api.attemptNudge(
          id,
          async () => connectBoundConversationWithReentry([neutral], conversation, {
            connector: async () => probe,
            selectReentry: async () => { events.push('select_reentry'); return neutral; },
            navigate: async () => { events.push('navigate'); return true; },
          }),
          undefined, undefined, conversation,
        );
        assert.equal(outcome.sent, false);
        assert.ok(events.includes('identity_probe'), 'fail-closed account probe must run');
        assert.equal(events.includes('select_reentry'), false, 'must not choose/create a target before checking Dev identity');
        assert.equal(events.includes('navigate'), false, 'must not navigate to a private Dev conversation in a wrong account');
      } finally {
        if (previous === undefined) delete process.env.SHOPVIVALIZ_CONTINUITY_DEV_ADOPTED_CDP;
        else process.env.SHOPVIVALIZ_CONTINUITY_DEV_ADOPTED_CDP = previous;
      }
    }],
    ['pre-cutover fred checkpoint remains compatible on CDP9555', async () => {
      assert.equal((await capture({ browser_session: 'fred' })).urls[0], 'http://127.0.0.1:9555/json/version');
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
        if (source.includes('continuity-conversation-unavailable-probe')) return false;
        if (source.includes('continuity-retry-button-target')) return null;
        throw Error('active stream must prevent all recovery effects');
      } };
      const result = await api.attemptNudge(id, async () => cdp, undefined, undefined, conversation);
      assert.equal(result.result_status, 'STALLED_NOT_CONFIRMED');
      assert.equal(result.sent, false);
      assert.match(result.detail, /active|unconfirmed/);
      assert.equal(
        evaluated.length,
        4,
        'active bound stream may add only the read-only native Retry discriminator before deferring',
      );
    }],
    ['same-session activity probe ignores target and recognizes an active sibling', async () => {
      const target = conversation;
      const sibling = 'active-sibling-conversation';
      const closed = [];
      const fetcher = async () => ({
        ok: true,
        async json() {
          return [
            { type: 'page', url: 'https://chatgpt.com/c/' + target, webSocketDebuggerUrl: 'ws://target' },
            { type: 'page', url: 'https://chatgpt.com/c/' + sibling, webSocketDebuggerUrl: 'ws://sibling' },
          ];
        },
      });
      const connector = async tab => ({
        async evaluate(source) {
          if (source.includes('stop-button')) return tab.url.endsWith(sibling);
          if (source.includes('/stream_status')) return { http_status: 200, status: 'IS_STREAMING' };
          throw Error('unexpected sibling probe');
        },
        close() { closed.push(tab.url); },
      });
      assert.equal(await api.anotherConversationActiveInSession(target, fetcher, connector), true);
      assert.deepEqual(closed, ['https://chatgpt.com/c/' + sibling]);
    }],
    ['another active conversation in the same browser session defers bound recovery before reload or input', async () => {
      write(); const evaluated = [];
      const cdp = { close() {}, async evaluate(source) {
        evaluated.push(source);
        if (source.includes('continuity-browser-account-match')) return vm.runInNewContext(source, { location: { pathname: '/c/' + conversation }, AbortSignal, fetch: async () => ({ ok: true, json: async () => ({ user: { email: 'atendimento@shopvivaliz.com.br' } }) }) });
        if (source.includes('/stream_status')) return { http_status: 200, status: 'COMPLETE' };
        throw Error('same-session active guard must prevent all recovery effects');
      } };
      const result = await api.attemptNudge(
        id,
        async () => cdp,
        undefined,
        undefined,
        conversation,
        async () => true,
      );
      assert.equal(result.result_status, 'STALLED_NOT_CONFIRMED');
      assert.equal(result.sent, false);
      assert.match(result.detail, /another conversation.*same browser session/i);
      assert.equal(evaluated.length, 2, 'guard should run after account and bound-stream checks, before any recovery effect');
    }],
    ['bound active stream with unavailable UI is treated as hydration recovery, not generic active deferral', async () => {
      write(); const evaluated = [];
      const cdp = { close() {}, async send() { throw Error('hydration recovery must not send trusted input'); }, async evaluate(source) {
        evaluated.push(source);
        if (source.includes('continuity-browser-account-match')) return vm.runInNewContext(source, { location: { pathname: '/c/' + conversation }, AbortSignal, fetch: async () => ({ ok: true, json: async () => ({ user: { email: 'atendimento@shopvivaliz.com.br' } }) }) });
        if (source === 'location.pathname') return '/c/' + conversation;
        if (source.includes('continuity-conversation-unavailable-probe')) return true;
        if (source.includes('/stream_status')) return { http_status: 200, status: 'IS_STREAMING' };
        if (source.includes('conversation-turn-state')) return { http_status: 200, node_id: 'bound-active-assistant', role: 'assistant', end_turn: false, child_count: 0, content_text_length: 0, message_status: 'finished_successfully' };
        if (source.includes('const candidates=[')) return { count: 1, lastText: 'prior reply', lastLength: 11, lastKey: 'assistant-prior', surfaceText: 'Could not load this ChatGPT conversation', surfaceLength: 39, conversationPath: '/c/' + conversation, snapshotSource: 'legacy' };
        if (source.includes('location.reload')) return true;
        if (source.includes('continuity-error-banner-probe')
          || source.includes('continuity-additional-checks-probe')
          || source.includes('continuity-stopped-thinking-probe')
          || source.includes('continuity-streaming-interrupted-probe')
          || source.includes('continuity-request-timeout-probe')
          || source.includes('stop-button')) return false;
        return false;
      } };
      const result = await api.attemptNudge(
        id,
        async () => cdp,
        async () => true,
        async () => { throw Error('composer path must not be reached'); },
        conversation,
      );
      assert.equal(result.result_status, 'PROGRESS_CONFIRMED');
      assert.equal(result.sent, false);
      assert.ok(evaluated.some(source => source.includes('continuity-conversation-unavailable-probe')));
      assert.ok(evaluated.some(source => source.includes('location.reload')));
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
