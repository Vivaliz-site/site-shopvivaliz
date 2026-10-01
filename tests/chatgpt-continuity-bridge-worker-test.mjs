import assert from 'node:assert/strict';
import {
  Cdp,
  conversationIsGenerating,
  conversationStreamStatus,
  conversationTurnState,
  silentStallPresent,
  composerIsUsable,
  waitForComposerUsable,
  errorBannerPresent,
  transmissionErrorPresent,
  latestConversationProbe,
  latestConversationMeta,
  alignToLatestConversation,
  alignLatestForReinforcement,
  assistantSnapshot,
  assistantProgressed,
  sendContinueMessage,
  attemptNudge,
  reinforcementCheckOnce,
  reinforcementDiscoveryDelayMs,
  reinforcementLoop,
  mainLoop,
  selectChatgptTab,
  connectFirstUsableChatgptTab,
  connectReinforcementChatgptTab,
  resolveAmbiguousConversationTabs,
} from '../scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs';

// Fake CDP objects let the decision logic (when to nudge, what result to
// report) be tested without a real browser or WebSocket -- exactly the
// branches attemptNudge() takes, driven purely by what evaluate()/pageState()
// return.
function fakeCdp({
  generating = false,
  composerUsable = true,
  pageText = '',
  sendSucceeds = true,
  streamStatus = 'IN_PROGRESS',
  staleStopClearSucceeds = true,
} = {}) {
  const calls = [];
  let currentGenerating = generating;
  return {
    calls,
    async evaluate(expression) {
      calls.push(expression);
      if (expression.includes('/stream_status')) return { http_status: 200, status: streamStatus };
      if (expression.includes('stale-complete-stop-clear')) {
        if (staleStopClearSucceeds) currentGenerating = false;
        return staleStopClearSucceeds;
      }
      if (expression.includes('stop-button')) return currentGenerating;
      if (expression.includes('prompt-textarea') && expression.includes('[role=\"textbox\"]') && !expression.includes('insertText') && !expression.includes('proto.value') && !expression.includes('b.click()')) return composerUsable;
      if (expression.includes('send-button') && expression.includes('!b.disabled')) return composerUsable;
      if (expression.includes('insertText') || expression.includes('proto.value')) return true;
      if (expression.includes('b.click()')) return sendSucceeds;
      return null;
    },
    async pageState() {
      return { href: 'https://chatgpt.com/c/fake', title: 'ChatGPT', text: pageText };
    },
    close() {},
  };
}

async function run() {
  {
    const started = Date.now();
    const result = await conversationStreamStatus(
      { evaluate: async () => new Promise(() => {}) },
      25,
    );
    const elapsed = Date.now() - started;
    assert.equal(result.http_status, 0);
    assert.equal(result.status, 'FETCH_TIMEOUT');
    assert.ok(elapsed < 500, `stream-status timeout must stay bounded, elapsed=${elapsed}ms`);
  }

  {
    const originalFetch = globalThis.fetch;
    let calls = 0;
    try {
      globalThis.fetch = async url => {
        calls += 1;
        if (String(url).endsWith('/json/version')) {
          return { ok: true, async json() { return {}; } };
        }
        if (String(url).endsWith('/json')) {
          return { ok: true, async json() { return []; } };
        }
        throw new Error(`unexpected URL ${url}`);
      };
      await assert.rejects(
        () => Cdp.connectToChatgptTab(),
        /CDP endpoint unreachable/,
        'a version response without webSocketDebuggerUrl must fail the readiness gate',
      );
      assert.equal(calls, 1, 'invalid /json/version must not proceed to the targets endpoint');
    } finally {
      globalThis.fetch = originalFetch;
    }
  }

  {
    const selected = selectChatgptTab([
      { type: 'page', url: 'https://chatgpt.com/auth/login', webSocketDebuggerUrl: 'ws://auth' },
      { type: 'page', url: 'https://chatgpt.com/gpts', webSocketDebuggerUrl: 'ws://other' },
      { type: 'page', url: 'https://chatgpt.com/c/active-thread', webSocketDebuggerUrl: 'ws://conversation' },
      { type: 'page', url: 'https://example.com/', webSocketDebuggerUrl: 'ws://external' },
    ]);
    assert.equal(selected?.webSocketDebuggerUrl, 'ws://conversation', 'conversation tab must win over auxiliary/auth ChatGPT tabs');
  }

  {
    const selected = selectChatgptTab([
      { type: 'page', url: 'https://chatgpt.com/auth/login', webSocketDebuggerUrl: 'ws://auth' },
      { type: 'page', url: 'https://chatgpt.com/', webSocketDebuggerUrl: 'ws://home' },
    ]);
    assert.equal(selected?.webSocketDebuggerUrl, 'ws://home', 'ChatGPT home must win over auth page when no conversation tab exists');
  }

  {
    const selected = selectChatgptTab([
      { type: 'page', url: 'https://chatgpt.com/gpts', webSocketDebuggerUrl: 'ws://aux' },
      { type: 'page', url: 'https://example.com/', webSocketDebuggerUrl: 'ws://external' },
    ]);
    assert.equal(selected?.webSocketDebuggerUrl, 'ws://aux', 'auxiliary ChatGPT tab remains a bounded fallback');
  }

  {
    const attempts = [];
    const connected = await connectFirstUsableChatgptTab([
      { type: 'page', url: 'https://chatgpt.com/c/stale', webSocketDebuggerUrl: 'ws://stale' },
      { type: 'page', url: 'https://chatgpt.com/c/live', webSocketDebuggerUrl: 'ws://live' },
      { type: 'page', url: 'https://chatgpt.com/', webSocketDebuggerUrl: 'ws://home' },
    ], async tab => {
      attempts.push(tab.webSocketDebuggerUrl);
      if (tab.webSocketDebuggerUrl === 'ws://stale') throw new Error('stale target');
      return { marker: tab.webSocketDebuggerUrl };
    });
    assert.deepEqual(attempts, ['ws://stale', 'ws://live'], 'must fall through stale same-rank target before lower-rank home');
    assert.equal(connected?.marker, 'ws://live');
  }

  {
    const originalFetch = globalThis.fetch;
    try {
      globalThis.fetch = async url => {
        if (String(url).endsWith('/json/version')) {
          return {
            ok: true,
            async json() {
              return { webSocketDebuggerUrl: 'ws://127.0.0.1:1/devtools/browser/test' };
            },
          };
        }
        if (String(url).endsWith('/json')) {
          return {
            ok: true,
            async json() {
              return [
                {
                  type: 'page',
                  url: 'https://chatgpt.com/c/conversation-one',
                  webSocketDebuggerUrl: 'ws://127.0.0.1:1/devtools/page/one',
                },
                {
                  type: 'page',
                  url: 'https://chatgpt.com/c/conversation-two',
                  webSocketDebuggerUrl: 'ws://127.0.0.1:1/devtools/page/two',
                },
              ];
            },
          };
        }
        throw new Error(`unexpected URL ${url}`);
      };
      await assert.rejects(
        () => Cdp.connectToChatgptTab(),
        /multiple open ChatGPT conversation tabs/i,
        'distinct open conversations must fail closed instead of selecting one arbitrarily',
      );
    } finally {
      globalThis.fetch = originalFetch;
    }
  }

  {
    const now = Date.now();
    const tabs = [
      { type: 'page', url: 'https://chatgpt.com/c/older-thread', webSocketDebuggerUrl: 'ws://older' },
      { type: 'page', url: 'https://chatgpt.com/c/latest-thread', webSocketDebuggerUrl: 'ws://latest-a' },
      { type: 'page', url: 'https://chatgpt.com/c/latest-thread', webSocketDebuggerUrl: 'ws://latest-b' },
      { type: 'page', url: 'https://chatgpt.com/', webSocketDebuggerUrl: 'ws://home' },
    ];
    let discoveryConnections = 0;
    let discoveryCloses = 0;
    const candidates = await resolveAmbiguousConversationTabs(
      tabs,
      async () => {
        discoveryConnections += 1;
        return { close() { discoveryCloses += 1; } };
      },
      async () => ({
        http_status: 200,
        source: 'filtered',
        id: 'latest-thread',
        update_time: Math.floor(now / 1000),
      }),
      now,
    );
    assert.equal(discoveryConnections, 1, 'ambiguous tabs need exactly one read-only discovery connection');
    assert.equal(discoveryCloses, 1, 'the temporary discovery connection must always close');
    assert.equal(candidates.length, 2, 'duplicate targets for the same latest conversation remain valid');
    assert.ok(
      candidates.every(tab => tab.url.includes('/c/latest-thread')),
      'only targets for the server-confirmed latest conversation may remain',
    );
  }

  {
    const now = Date.now();
    const tabs = [
      { type: 'page', url: 'https://chatgpt.com/c/older-one', webSocketDebuggerUrl: 'ws://older-one' },
      { type: 'page', url: 'https://chatgpt.com/c/older-two', webSocketDebuggerUrl: 'ws://older-two' },
      { type: 'page', url: 'https://chatgpt.com/', webSocketDebuggerUrl: 'ws://home' },
    ];
    const navigations = [];
    const candidates = await resolveAmbiguousConversationTabs(
      tabs,
      async () => ({ close() {} }),
      async () => ({
        http_status: 200,
        source: 'filtered',
        id: 'latest-not-open',
        update_time: Math.floor(now / 1000),
      }),
      now,
      undefined,
      async (tab, conversationId) => {
        navigations.push({ tab: tab.webSocketDebuggerUrl, conversationId });
        return true;
      },
    );
    assert.deepEqual(
      navigations,
      [{ tab: 'ws://home', conversationId: 'latest-not-open' }],
      'server-confirmed latest conversation that is not open must use the neutral ChatGPT home tab',
    );
    assert.equal(candidates.length, 1);
    assert.equal(
      candidates[0]?.webSocketDebuggerUrl,
      'ws://home',
      'the neutral tab becomes the only eligible continuation target after navigation',
    );
  }

  {
    const now = Date.now();
    const tabs = [
      { type: 'page', url: 'https://chatgpt.com/c/older-one', webSocketDebuggerUrl: 'ws://older-one' },
      { type: 'page', url: 'https://chatgpt.com/c/older-two', webSocketDebuggerUrl: 'ws://older-two' },
      { type: 'page', url: 'https://chatgpt.com/', webSocketDebuggerUrl: 'ws://home' },
    ];
    let navigated = 0;
    const candidates = await resolveAmbiguousConversationTabs(
      tabs,
      async () => ({ close() {} }),
      async () => ({
        http_status: 200,
        source: 'filtered',
        id: 'latest-not-open',
        update_time: Math.floor((now - 14 * 60 * 1000) / 1000),
      }),
      now,
      undefined,
      async () => { navigated += 1; return true; },
    );
    assert.equal(navigated, 1, '14-minute latest-not-open metadata must remain inside the bounded navigation window');
    assert.equal(candidates.length, 1);
    assert.equal(candidates[0]?.webSocketDebuggerUrl, 'ws://home');
  }

  {
    const now = Date.now();
    const tabs = [
      { type: 'page', url: 'https://chatgpt.com/c/older-one', webSocketDebuggerUrl: 'ws://older-one' },
      { type: 'page', url: 'https://chatgpt.com/c/older-two', webSocketDebuggerUrl: 'ws://older-two' },
      { type: 'page', url: 'https://chatgpt.com/', webSocketDebuggerUrl: 'ws://home' },
    ];
    await assert.rejects(
      () => resolveAmbiguousConversationTabs(
        tabs,
        async () => ({ close() {} }),
        async () => ({
          http_status: 200,
          source: 'filtered',
          id: 'latest-not-open',
          update_time: Math.floor((now - 16 * 60 * 1000) / 1000),
        }),
        now,
        undefined,
        async () => true,
      ),
      /multiple open ChatGPT conversation tabs/i,
      'latest-not-open navigation older than 15 minutes must remain fail-closed',
    );
  }

  {
    const now = Date.now();
    const tabs = [
      { type: 'page', url: 'https://chatgpt.com/c/older-one', webSocketDebuggerUrl: 'ws://older-one' },
      { type: 'page', url: 'https://chatgpt.com/c/older-two', webSocketDebuggerUrl: 'ws://older-two' },
    ];
    await assert.rejects(
      () => resolveAmbiguousConversationTabs(
        tabs,
        async () => ({ close() {} }),
        async () => ({
          http_status: 200,
          source: 'filtered',
          id: 'latest-not-open',
          update_time: Math.floor(now / 1000),
        }),
        now,
      ),
      /multiple open ChatGPT conversation tabs/i,
      'latest-not-open must remain fail-closed when no neutral ChatGPT home tab exists',
    );
  }

  {
    const now = Date.now();
    const tabs = [
      { type: 'page', url: 'https://chatgpt.com/c/older-thread', webSocketDebuggerUrl: 'ws://older' },
      { type: 'page', url: 'https://chatgpt.com/c/latest-thread', webSocketDebuggerUrl: 'ws://latest-a' },
      { type: 'page', url: 'https://chatgpt.com/c/latest-thread', webSocketDebuggerUrl: 'ws://latest-b' },
    ];
    const candidates = await resolveAmbiguousConversationTabs(
      tabs,
      async () => ({ close() {} }),
      async () => ({
        http_status: 200,
        source: 'filtered',
        id: 'latest-thread',
        update_time: Math.floor((now - 14 * 60 * 1000) / 1000),
      }),
      now,
    );
    assert.equal(
      candidates.length,
      2,
      'checkpoint-driven disambiguation must tolerate the live 14-minute latest age without weakening reinforcement recency',
    );
  }

  {
    const now = Date.now();
    const tabs = [
      { type: 'page', url: 'https://chatgpt.com/c/older-thread', webSocketDebuggerUrl: 'ws://older' },
      { type: 'page', url: 'https://chatgpt.com/c/latest-thread', webSocketDebuggerUrl: 'ws://latest' },
    ];
    await assert.rejects(
      () => resolveAmbiguousConversationTabs(
        tabs,
        async () => ({ close() {} }),
        async () => ({ http_status: 429, source: 'filtered', item_present: false }),
        now,
      ),
      /multiple open ChatGPT conversation tabs/i,
      '429/latest-unavailable must remain fail-closed instead of guessing a target',
    );
    await assert.rejects(
      () => resolveAmbiguousConversationTabs(
        tabs,
        async () => ({ close() {} }),
        async () => ({
          http_status: 200,
          source: 'filtered',
          id: 'latest-thread',
          update_time: Math.floor((now - 31 * 60 * 1000) / 1000),
        }),
        now,
      ),
      /multiple open ChatGPT conversation tabs/i,
      'a latest conversation outside the bounded 30-minute checkpoint window must remain fail-closed',
    );
  }

  // Current ChatGPT Web (2026-09-30) no longer exposes assistant turns only
  // through data-message-author-role. Progress confirmation must also see the
  // virtualized data-turn-key/data-conversation-role structure, without
  // dropping the legacy selector.
  {
    let expressionSeen = '';
    const cdp = {
      async evaluate(expression) {
        expressionSeen = expression;
        return { count: 1, lastText: 'done', lastLength: 4, lastKey: 'turn-current' };
      },
    };
    const snapshot = await assistantSnapshot(cdp);
    assert.equal(snapshot.count, 1);
    assert.match(expressionSeen, /data-message-author-role/);
    assert.match(expressionSeen, /data-conversation-role/);
    assert.match(expressionSeen, /data-turn-key/);
  }
  assert.equal(
    assistantProgressed(
      { count: 1, lastText: 'a much longer previous answer', lastLength: 29, lastKey: 'turn-old' },
      { count: 1, lastText: 'ok', lastLength: 2, lastKey: 'turn-new' },
    ),
    true,
    'a new assistant turn key must confirm progress even when virtualization keeps count stable and the new answer is shorter',
  );

  // conversationIsGenerating / composerIsUsable / errorBannerPresent are
  // thin wrappers -- confirm they read the right signal.
  assert.equal(await conversationIsGenerating(fakeCdp({ generating: true })), true);
  assert.equal(await conversationIsGenerating(fakeCdp({ generating: false })), false);
  assert.equal(await composerIsUsable(fakeCdp({ composerUsable: false })), false);

  {
    const cdp = {
      async evaluate(expression) {
        if (expression.includes('[role="textbox"][contenteditable="true"]')) return true;
        return false;
      },
    };
    assert.equal(
      await composerIsUsable(cdp),
      true,
      'current ChatGPT contenteditable role=textbox composer must be accepted without legacy data-testid',
    );
  }

  {
    let checks = 0;
    const cdp = {
      async evaluate(expression) {
        if (
          expression.includes('prompt-textarea')
          && expression.includes('[role="textbox"][contenteditable="true"]')
          && !expression.includes('insertText')
          && !expression.includes('proto.value')
          && !expression.includes('b.click()')
        ) {
          checks += 1;
          return checks >= 3;
        }
        return false;
      },
    };
    assert.equal(
      await waitForComposerUsable(cdp, 50, 1),
      true,
      'transient post-reattach composer absence must recover within a bounded wait',
    );
    assert.equal(checks, 3);
  }

  {
    const calls = [];
    const cdp = {
      async evaluate(expression) {
        calls.push(expression);
        if (expression.includes('insertText') || expression.includes('proto.value')) {
          return expression.includes('[role="textbox"][contenteditable="true"]');
        }
        if (expression.includes('b.click()')) {
          return /Send prompt|Send message|Enviar prompt|Enviar mensagem/.test(expression);
        }
        return false;
      },
    };
    assert.equal(
      await sendContinueMessage(cdp),
      true,
      'fallback composer and aria-labelled send button must support the current ChatGPT DOM',
    );
    assert.ok(calls.length >= 2);
  }
  assert.equal(await errorBannerPresent(fakeCdp({ pageText: 'Something went wrong. Please try again.' })), true);
  assert.equal(await errorBannerPresent(fakeCdp({ pageText: 'Here is your normal completed answer.' })), false);
  // Confirmed live on ChatGPT Free (mobile app), 2026-09-27 -- the actual
  // observed banner text, not a guess.
  assert.equal(
    await errorBannerPresent(fakeCdp({ pageText: 'Transmissão interrompida. Aguardando a mensagem completa...' })),
    true,
  );
  assert.equal(await errorBannerPresent(fakeCdp({ pageText: 'Streaming interrupted. Waiting for the complete message...' })), true);
  // Confirmed live on ChatGPT iOS, 2026-09-28: the app can stop a turn
  // with an explicit "Parou de pensar" state instead of the stream banner.
  assert.equal(await errorBannerPresent(fakeCdp({ pageText: 'Parou de pensar' })), true);
  assert.equal(await errorBannerPresent(fakeCdp({ pageText: 'Stopped thinking' })), true);
  assert.equal(await transmissionErrorPresent(fakeCdp({ pageText: 'Erro na transmissão de mensagem' })), true);
  assert.equal(await transmissionErrorPresent(fakeCdp({ pageText: 'normal completed answer' })), false);

  console.log('conversationIsGenerating/composerIsUsable/errorBannerPresent/transmissionErrorPresent: PASS');

  // Cross-device continuity: a mobile/iOS interruption may belong to a
  // different thread than the backend browser currently has open. Discovery
  // must use only conversation id/update metadata, never title/content.
  {
    const cdp = fakeCdp();
    cdp.evaluate = async expression => {
      cdp.calls.push(expression);
      if (expression.includes('/backend-api/conversations?')) {
        return { id: 'latest-thread', update_time: Math.floor(Date.now() / 1000) };
      }
      return null;
    };
    const latest = await latestConversationMeta(cdp);
    assert.equal(latest.id, 'latest-thread');
    assert.ok(latest.update_time > 0);
  }

  {
    const iso = new Date().toISOString();
    const cdp = fakeCdp();
    cdp.evaluate = async expression => {
      cdp.calls.push(expression);
      if (expression.includes('/backend-api/conversations?')) {
        assert.match(expression, /is_archived=false/);
        assert.match(expression, /is_starred=false/);
        return { id: 'latest-thread-iso', update_time: iso };
      }
      return null;
    };
    const latest = await latestConversationMeta(cdp);
    assert.equal(latest.id, 'latest-thread-iso');
    assert.ok(latest.update_time > 0, 'ISO update_time must normalize to unix seconds');
  }

  {
    let navigatedTo = '';
    const now = Date.now();
    const cdp = fakeCdp();
    cdp.evaluate = async expression => {
      cdp.calls.push(expression);
      if (expression.includes('location.pathname')) return '/c/older-thread';
      if (expression.includes('location.assign')) {
        navigatedTo = expression;
        return true;
      }
      return null;
    };
    const result = await alignToLatestConversation(
      cdp,
      async () => ({ id: 'latest-thread', update_time: Math.floor(now / 1000) }),
      now,
    );
    assert.equal(result.action, 'navigated');
    assert.match(navigatedTo, /latest-thread/);
  }

  {
    const now = Date.now();
    const cdp = fakeCdp();
    const result = await alignToLatestConversation(
      cdp,
      async () => ({ id: 'stale-thread', update_time: Math.floor((now - 20 * 60 * 1000) / 1000) }),
      now,
    );
    assert.equal(result.action, 'stale_latest');
    assert.equal(cdp.calls.some(call => call.includes('location.assign')), false);
  }

  {
    const iso = new Date().toISOString();
    const cdp = fakeCdp();
    cdp.evaluate = async expression => {
      cdp.calls.push(expression);
      assert.match(expression, /is_archived=false/);
      assert.match(expression, /is_starred=false/);
      assert.match(expression, /order=updated/);
      assert.match(expression, /\/api\/auth\/session/);
      assert.match(expression, /session\?\.account\?\.id/);
      assert.match(expression, /accessToken/);
      assert.match(expression, /Authorization/);
      assert.match(expression, /Bearer/);
      assert.match(expression, /ChatGPT-Account-Id/);
      assert.doesNotMatch(expression, /https:\/\/api\.openai\.com\/auth/);
      assert.match(expression, /body\?\.conversations/);
      assert.match(expression, /fallback_unfiltered/);
      return {id:'latest-thread-updated-at',updated_at:iso,http_status:200,source:'filtered'};
    };
    const latest = await latestConversationMeta(cdp);
    assert.equal(latest.id, 'latest-thread-updated-at');
    assert.ok(latest.update_time > 0, 'updated_at must normalize to unix seconds');
  }

  {
    const cdp = fakeCdp();
    cdp.evaluate = async expression => {
      cdp.calls.push(expression);
      assert.match(expression, /Object\.keys\(item\)/);
      return {
        http_status: 200,
        source: 'filtered',
        item_present: true,
        item_keys: ['id', 'update_time'],
        id: 'latest-thread-schema',
        update_time: new Date().toISOString(),
      };
    };
    const probe = await latestConversationProbe(cdp);
    assert.deepEqual(probe.item_keys, ['id', 'update_time']);
  }

  {
    const cdp = fakeCdp();
    cdp.evaluate = async expression => {
      cdp.calls.push(expression);
      return new Promise(() => {});
    };
    const timeoutSentinel = { source: 'test_timeout' };
    const probe = await Promise.race([
      latestConversationProbe(cdp, 25),
      new Promise(resolve => setTimeout(() => resolve(timeoutSentinel), 100)),
    ]);
    assert.notEqual(
      probe.source,
      'test_timeout',
      'latestConversationProbe must bound a CDP evaluation that never resolves',
    );
    assert.equal(probe.source, 'probe_failed');
  }

  console.log('cross-device latest-conversation alignment: PASS');

  // Live canonical reproduction 2026-09-30: stream_status can be COMPLETE
  // while current_node ends in assistant end_turn=false with no child and the
  // UI exposes no Stop button. A silent stall must therefore receive the same
  // passive reattach/reload before any continuation send.
  {
    const silentCdp = fakeCdp({ generating: false, sendSucceeds: true });
    const recoveredSilent = await attemptNudge(
      'task-silent-stall-passive-reattach',
      async () => silentCdp,
      async () => true,
    );
    assert.equal(recoveredSilent.result_status, 'PROGRESS_CONFIRMED');
    assert.match(recoveredSilent.detail, /passive reattach/i);
    assert.equal(
      silentCdp.calls.some(call => call.includes('location.reload')),
      true,
      'silent stalls without Stop must still attempt passive reattach',
    );
    assert.equal(
      silentCdp.calls.some(call => call.includes('b.click()')),
      false,
      'passive reattach progress must suppress continuation send',
    );
  }

  // The explicitly authorized checkpoint-driven resume path must stay live.
  // Safety is enforced by stream/composer/checkpoint guards, not by globally
  // disabling Web turn submission.
  const activeCdp = fakeCdp({ generating: true });
  const generating = await attemptNudge('task-1', async () => activeCdp, async () => false);
  assert.equal(generating.result_status, 'STALLED_NOT_CONFIRMED', 'must never inject a continuation into an unconfirmed active stream');
  assert.equal(
    activeCdp.calls.some(call => call.includes('location.reload')),
    true,
    'stale checkpoint plus apparent active stream must attempt one passive reattach before deferring',
  );
  assert.equal(
    activeCdp.calls.some(call => call.includes('b.click()')),
    false,
    'passive reattach must not inject a duplicate continue message',
  );
  assert.match(generating.detail, /passive reattach/i);

  const recoveredCdp = fakeCdp({ generating: true });
  const recoveredByReattach = await attemptNudge(
    'task-passive-reattach',
    async () => recoveredCdp,
    async () => true,
  );
  assert.equal(recoveredByReattach.result_status, 'PROGRESS_CONFIRMED');
  assert.match(recoveredByReattach.detail, /passive reattach/i);
  assert.equal(
    recoveredCdp.calls.some(call => call.includes('b.click()')),
    false,
    'reattach recovery that restores assistant progress must not send continue',
  );

  // Live failure reproduced 2026-09-28: the DOM can keep a stale Stop button
  // even when the conversation backend already reports stream_status=COMPLETE.
  // Independent 2026-09 evidence also shows transport COMPLETE can coexist
  // with a semantically unfinished turn. Therefore even this branch must
  // perform one passive reattach before any Stop clear or continuation send.
  const staleCompleteCdp = fakeCdp({
    generating: true,
    streamStatus: 'COMPLETE',
    staleStopClearSucceeds: true,
    sendSucceeds: true,
  });
  let staleCompleteConfirmCalls = 0;
  const staleComplete = await attemptNudge(
    'task-stale-complete',
    async () => staleCompleteCdp,
    async () => {
      staleCompleteConfirmCalls += 1;
      return staleCompleteConfirmCalls > 1;
    },
  );
  assert.equal(staleComplete.result_status, 'PROGRESS_CONFIRMED', 'stale COMPLETE recovery is success only after assistant progress');
  assert.match(staleComplete.detail, /stale COMPLETE/i);
  const staleCompleteReload = staleCompleteCdp.calls.findIndex(call => call.includes('location.reload'));
  const staleCompleteSend = staleCompleteCdp.calls.findIndex(call => call.includes('b.click()'));
  assert.ok(staleCompleteReload >= 0, 'COMPLETE plus Stop must still reattach passively before recovery');
  assert.ok(staleCompleteSend > staleCompleteReload, 'continuation send must occur only after passive reattach failed to restore progress');

  const completeRecoveredCdp = fakeCdp({
    generating: true,
    streamStatus: 'COMPLETE',
    staleStopClearSucceeds: true,
    sendSucceeds: true,
  });
  const completeRecovered = await attemptNudge(
    'task-complete-passive-recovery',
    async () => completeRecoveredCdp,
    async () => true,
  );
  assert.equal(completeRecovered.result_status, 'PROGRESS_CONFIRMED');
  assert.match(completeRecovered.detail, /passive reattach/i);
  assert.equal(
    completeRecoveredCdp.calls.some(call => call.includes('b.click()')),
    false,
    'COMPLETE state recovered by passive reattach must not send a continuation',
  );

  const noComposer = await attemptNudge(
    'task-1',
    async () => fakeCdp({ composerUsable: false }),
    async () => false,
    async () => false,
  );
  assert.equal(noComposer.result_status, 'CONVERSATION_NOT_FOUND');

  {
    let waitCalls = 0;
    const transientComposerCdp = fakeCdp({ composerUsable: false, sendSucceeds: true });
    const transientComposer = await attemptNudge(
      'task-transient-composer-after-reattach',
      async () => transientComposerCdp,
      async () => false,
      async () => {
        waitCalls += 1;
        return true;
      },
    );
    assert.equal(waitCalls, 1, 'attemptNudge must use the bounded composer wait exactly once');
    assert.notEqual(
      transientComposer.result_status,
      'CONVERSATION_NOT_FOUND',
      'a composer that becomes usable after reattach must not be reported missing',
    );
  }

  let sentOkConfirmCalls = 0;
  const sentOkCdp = fakeCdp({ sendSucceeds: true });
  const sentOk = await attemptNudge(
    'task-1',
    async () => sentOkCdp,
    async () => {
      sentOkConfirmCalls += 1;
      return sentOkConfirmCalls > 1;
    },
  );
  assert.equal(sentOk.result_status, 'PROGRESS_CONFIRMED');
  assert.ok(sentOkCdp.calls.findIndex(call => call.includes('location.reload')) >= 0);
  assert.ok(
    sentOkCdp.calls.findIndex(call => call.includes('b.click()'))
      > sentOkCdp.calls.findIndex(call => call.includes('location.reload')),
    'send path must remain available after passive reattach found no progress',
  );

  const sentButNoProgress = await attemptNudge('task-no-progress', async () => fakeCdp({ sendSucceeds: true }), async () => false);
  assert.equal(sentButNoProgress.result_status, 'SENT_UNCONFIRMED');
  assert.match(sentButNoProgress.detail, /no assistant progress/i);

  {
    let sendCalls = 0;
    let reloads = 0;
    let pageText = 'normal';
    const transmissionCdp = fakeCdp({ sendSucceeds: true });
    const originalEvaluate = transmissionCdp.evaluate.bind(transmissionCdp);
    transmissionCdp.evaluate = async expression => {
      if (expression.includes('insertText') || expression.includes('proto.value')) {
        sendCalls += 1;
      }
      if (expression.includes('b.click()')) {
        sendCalls += 1;
        pageText = 'Erro na transmissão de mensagem';
      }
      if (expression.includes('location.reload')) {
        reloads += 1;
        if (reloads >= 2) pageText = 'normal';
      }
      return originalEvaluate(expression);
    };
    transmissionCdp.pageState = async () => ({
      href: 'https://chatgpt.com/c/fake',
      title: 'ChatGPT',
      text: pageText,
    });
    let confirmCalls = 0;
    const recovered = await attemptNudge(
      'task-transmission-error-recovery',
      async () => transmissionCdp,
      async () => {
        confirmCalls += 1;
        return confirmCalls >= 3;
      },
    );
    assert.equal(recovered.result_status, 'PROGRESS_CONFIRMED');
    assert.match(recovered.detail, /transmission error recovered/i);
    assert.ok(sendCalls >= 2, 'explicit transmission error must get one bounded retry');

    const persistent = fakeCdp({ sendSucceeds: true });
    persistent.pageState = async () => ({
      href: 'https://chatgpt.com/c/fake',
      title: 'ChatGPT',
      text: 'Erro na transmissão de mensagem',
    });
    const failed = await attemptNudge(
      'task-transmission-error-persistent',
      async () => persistent,
      async () => false,
    );
    assert.equal(failed.result_status, 'ERROR');
    assert.match(failed.detail, /transmission error persisted/i);
  }

  const sendFailed = await attemptNudge(
    'task-1',
    async () => fakeCdp({ sendSucceeds: false }),
    async () => false,
  );
  assert.equal(sendFailed.result_status, 'ERROR');

  const connectFailed = await attemptNudge('task-1', async () => { throw new Error('CDP endpoint unreachable'); });
  assert.equal(connectFailed.result_status, 'ERROR');
  assert.ok(connectFailed.detail.includes('unreachable'), 'connect failures must surface their reason in detail');

  console.log('attemptNudge branches: PASS');

  // Duplicate tabs for the same interrupted conversation are one target,
  // not an ambiguity. This reproduces the live VM state observed on 2026-10-01.
  {
    const tabs = [
      { type: 'page', url: 'https://chatgpt.com/c/same-thread', webSocketDebuggerUrl: 'ws://same-a' },
      { type: 'page', url: 'https://chatgpt.com/c/same-thread', webSocketDebuggerUrl: 'ws://same-b' },
    ];
    const closed = [];
    const connector = async tab => {
      const cdp = fakeCdp({ pageText: 'Erro na transmissão de mensagem' });
      cdp.close = () => { closed.push(tab.webSocketDebuggerUrl); };
      return cdp;
    };
    const selected = await connectReinforcementChatgptTab({
      tabs,
      connector,
      probeBanner: async () => true,
      allowCrossDeviceDiscovery: true,
    });
    assert.ok(selected);
    assert.equal(closed.length, 1, 'duplicate same-conversation tab must be deduplicated');
    selected.close();
  }

  // Multiple open conversations must not block the reinforcement monitor before
  // it can preserve a 429 and schedule the wider discovery backoff. The local
  // connector first scans for exactly one interrupted tab; with no banner it
  // uses the single neutral home tab only as a read-only discovery context.
  {
    const tabs = [
      { type: 'page', url: 'https://chatgpt.com/c/older-one', webSocketDebuggerUrl: 'ws://older-one' },
      { type: 'page', url: 'https://chatgpt.com/c/older-two', webSocketDebuggerUrl: 'ws://older-two' },
      { type: 'page', url: 'https://chatgpt.com/', webSocketDebuggerUrl: 'ws://home' },
    ];
    const closed = [];
    const connector = async tab => {
      const cdp = fakeCdp({ pageText: 'normal reply' });
      cdp.marker = tab.webSocketDebuggerUrl;
      cdp.close = () => { closed.push(tab.webSocketDebuggerUrl); };
      return cdp;
    };
    const selected = await connectReinforcementChatgptTab({
      tabs,
      connector,
      probeBanner: async () => false,
      allowCrossDeviceDiscovery: true,
    });
    assert.equal(selected.marker, 'ws://home', 'cross-device discovery must prefer the single neutral home tab');
    selected.close();

    const result = await reinforcementCheckOnce(
      async () => connectReinforcementChatgptTab({
        tabs,
        connector,
        probeBanner: async () => false,
        allowCrossDeviceDiscovery: true,
      }),
      1,
      async () => true,
      async () => ({ action: 'latest_unavailable', http_status: 429 }),
      { allowCrossDeviceDiscovery: true },
    );
    assert.equal(result.action, 'latest_unavailable');
    assert.equal(result.http_status, 429);
    assert.equal(result.cross_device_discovery, true);
  }

  // Live backend topology 2026-09-30: one conversation plus two neutral
  // ChatGPT home tabs. Multiple home tabs are equivalent safe discovery
  // contexts; choose the first deterministically instead of falling back to
  // the conversation and making the 429 sidebar fallback unavailable.
  {
    const tabs = [
      { type: 'page', url: 'https://chatgpt.com/c/only-conversation', webSocketDebuggerUrl: 'ws://conversation' },
      { type: 'page', url: 'https://chatgpt.com/', webSocketDebuggerUrl: 'ws://home-a' },
      { type: 'page', url: 'https://chatgpt.com/', webSocketDebuggerUrl: 'ws://home-b' },
    ];
    const closed = [];
    const connector = async tab => {
      const cdp = fakeCdp({ pageText: 'normal reply' });
      cdp.marker = tab.webSocketDebuggerUrl;
      cdp.close = () => { closed.push(tab.webSocketDebuggerUrl); };
      return cdp;
    };
    const selected = await connectReinforcementChatgptTab({
      tabs,
      connector,
      probeBanner: async () => false,
      allowCrossDeviceDiscovery: true,
    });
    assert.equal(
      selected.marker,
      'ws://home-a',
      'multiple neutral home tabs must select the first home deterministically for cross-device discovery',
    );
    assert.ok(closed.includes('ws://conversation'), 'conversation CDP must not remain selected when neutral home context exists');
    assert.ok(closed.includes('ws://home-b'), 'unused neutral home CDP must be closed');
    selected.close();
  }

  // Live backend topology 2026-09-30: multiple conversation tabs, no neutral
  // home, and stale sidebars that disagree. A unique modal first sidebar item
  // (2-1-1) is sufficient local evidence to choose one idle voter as a
  // temporary discovery context. A tie remains fail-closed.
  {
    const tabs = [
      { type: 'page', url: 'https://chatgpt.com/c/open-alpha', webSocketDebuggerUrl: 'ws://a' },
      { type: 'page', url: 'https://chatgpt.com/c/open-bravo', webSocketDebuggerUrl: 'ws://b' },
      { type: 'page', url: 'https://chatgpt.com/c/open-charlie', webSocketDebuggerUrl: 'ws://c' },
      { type: 'page', url: 'https://chatgpt.com/c/open-delta', webSocketDebuggerUrl: 'ws://d' },
    ];
    const sidebarLatestByMarker = new Map([
      ['ws://a', '/c/mobile-latest'],
      ['ws://b', '/c/mobile-latest'],
      ['ws://c', '/c/stale-charlie'],
      ['ws://d', '/c/stale-delta'],
    ]);
    const connector = async tab => {
      const cdp = fakeCdp({ pageText: 'normal reply', generating: false });
      cdp.marker = tab.webSocketDebuggerUrl;
      const originalEvaluate = cdp.evaluate.bind(cdp);
      cdp.evaluate = async expression => {
        if (String(expression).includes('sidebar-latest-conversation')) {
          return sidebarLatestByMarker.get(cdp.marker) || '';
        }
        return originalEvaluate(expression);
      };
      return cdp;
    };
    const selected = await connectReinforcementChatgptTab({
      tabs,
      connector,
      probeBanner: async () => false,
      allowCrossDeviceDiscovery: true,
    });
    assert.ok(
      selected.marker === 'ws://a' || selected.marker === 'ws://b',
      'unique sidebar mode must select an idle tab that voted for the modal latest conversation',
    );

    let currentPath = selected.marker === 'ws://a' ? '/c/open-alpha' : '/c/open-bravo';
    const selectedEvaluate = selected.evaluate.bind(selected);
    selected.evaluate = async expression => {
      const source = String(expression);
      if (source.trim() === 'location.pathname') return currentPath;
      if (source.includes('sidebar-latest-conversation')) return '/c/mobile-latest';
      if (source.includes('location.assign')) {
        currentPath = '/c/mobile-latest';
        return true;
      }
      return selectedEvaluate(expression);
    };
    const aligned = await alignLatestForReinforcement(
      selected,
      async () => ({ http_status: 429, source: 'filtered', item_present: false, item_keys: [] }),
    );
    assert.equal(aligned.action, 'navigated_sidebar_fallback');
    assert.equal(aligned.sidebar_fallback, true);
    assert.equal(currentPath, '/c/mobile-latest');
    selected.close();
  }

  {
    const tabs = [
      { type: 'page', url: 'https://chatgpt.com/c/open-alpha', webSocketDebuggerUrl: 'ws://a' },
      { type: 'page', url: 'https://chatgpt.com/c/open-bravo', webSocketDebuggerUrl: 'ws://b' },
      { type: 'page', url: 'https://chatgpt.com/c/open-charlie', webSocketDebuggerUrl: 'ws://c' },
      { type: 'page', url: 'https://chatgpt.com/c/open-delta', webSocketDebuggerUrl: 'ws://d' },
    ];
    const sidebarLatestByMarker = new Map([
      ['ws://a', '/c/latest-x'],
      ['ws://b', '/c/latest-x'],
      ['ws://c', '/c/latest-y'],
      ['ws://d', '/c/latest-y'],
    ]);
    const connector = async tab => {
      const cdp = fakeCdp({ pageText: 'normal reply', generating: false });
      cdp.marker = tab.webSocketDebuggerUrl;
      const originalEvaluate = cdp.evaluate.bind(cdp);
      cdp.evaluate = async expression => {
        if (String(expression).includes('sidebar-latest-conversation')) {
          return sidebarLatestByMarker.get(cdp.marker) || '';
        }
        return originalEvaluate(expression);
      };
      return cdp;
    };
    await assert.rejects(
      () => connectReinforcementChatgptTab({
        tabs,
        connector,
        probeBanner: async () => false,
        allowCrossDeviceDiscovery: true,
      }),
      /continuity target is ambiguous/,
      '2-2 sidebar split must remain fail-closed',
    );
  }

  // If one and only one open tab carries the interruption banner, local
  // evidence wins and no account-scoped latest-conversation request is needed.
  {
    const tabs = [
      { type: 'page', url: 'https://chatgpt.com/c/healthy', webSocketDebuggerUrl: 'ws://healthy' },
      { type: 'page', url: 'https://chatgpt.com/c/interrupted', webSocketDebuggerUrl: 'ws://interrupted' },
      { type: 'page', url: 'https://chatgpt.com/', webSocketDebuggerUrl: 'ws://home' },
    ];
    const connector = async tab => {
      const cdp = fakeCdp({ pageText: tab.webSocketDebuggerUrl === 'ws://interrupted'
        ? 'Streaming interrupted. Waiting for the complete message...'
        : 'normal reply' });
      cdp.marker = tab.webSocketDebuggerUrl;
      return cdp;
    };
    const selected = await connectReinforcementChatgptTab({
      tabs,
      connector,
      probeBanner: async cdp => cdp.marker === 'ws://interrupted',
      allowCrossDeviceDiscovery: false,
    });
    assert.equal(selected.marker, 'ws://interrupted', 'the unique locally interrupted tab must be selected without guessing');
    selected.close();
  }

  // Reinforcement must inspect the currently open conversation first.
  // A visible failure banner must be handled without any account-scoped
  // latest-conversation discovery.
  {
    const events = [];
    let connectCalls = 0;
    const result = await reinforcementCheckOnce(
      async () => {
        connectCalls += 1;
        events.push('connect');
        return fakeCdp({ pageText: 'Streaming interrupted. Waiting for the complete message...' });
      },
      1,
      async () => true,
      async () => { events.push('align'); return { action: 'already_latest' }; },
      { allowCrossDeviceDiscovery: true },
    );
    assert.equal(result.action, 'confirmed_progress');
    assert.equal(events.includes('align'), false, 'current-tab banner must win before cross-device discovery');
    assert.equal(connectCalls, 2, 'confirmed banner still receives exactly one delayed re-check');
  }

  // Clean current tab + discovery window closed -> cheap no-op. This keeps
  // account-scoped discovery out of the fast reinforcement cadence.
  {
    let connectCalls = 0;
    let alignCalls = 0;
    const result = await reinforcementCheckOnce(
      async () => { connectCalls += 1; return fakeCdp({ pageText: 'normal reply' }); },
      1,
      async () => true,
      async () => { alignCalls += 1; return { action: 'already_latest' }; },
      { allowCrossDeviceDiscovery: false },
    );
    assert.equal(result.action, 'no_banner');
    assert.equal(result.cross_device_discovery, false);
    assert.equal(connectCalls, 1);
    assert.equal(alignCalls, 0, 'closed discovery window must not query latest conversations');
  }

  // When the wider discovery window opens, a clean current tab may inspect
  // the latest cross-device conversation once.
  {
    const events = [];
    const cdp = fakeCdp({ pageText: 'normal reply' });
    const result = await reinforcementCheckOnce(
      async () => { events.push('connect'); return cdp; },
      1,
      async () => true,
      async () => { events.push('align'); return { action: 'already_latest', http_status: 200 }; },
      { allowCrossDeviceDiscovery: true },
    );
    assert.equal(result.action, 'no_banner');
    assert.equal(result.cross_device_discovery, true);
    assert.deepEqual(events.slice(0, 2), ['connect', 'align']);
  }

  // When the account-scoped latest-conversation endpoint is rate-limited,
  // the already-synchronized sidebar may safely identify the newest visible
  // conversation without another backend-api request. The fallback is only
  // valid from the single neutral home tab selected by the connector.
  {
    const base = fakeCdp({ pageText: 'normal reply' });
    const originalEvaluate = base.evaluate.bind(base);
    base.evaluate = async expression => {
      if (String(expression).includes('sidebar-latest-conversation')) return '/c/sidebar-latest';
      if (String(expression).trim() === 'location.pathname') return '/';
      if (String(expression).includes('location.assign')) return true;
      return originalEvaluate(expression);
    };
    const result = await alignLatestForReinforcement(
      base,
      async () => ({ http_status: 429, source: 'filtered', item_present: false, item_keys: [] }),
    );
    assert.equal(result.action, 'navigated_sidebar_fallback');
    assert.equal(result.http_status, 429);
    assert.equal(result.sidebar_fallback, true);
  }

  // Live post-deploy state can contain exactly one conversation tab and no
  // neutral home tab. That tab is safe to use as temporary discovery context,
  // but only because connectReinforcementChatgptTab proved it is unique.
  {
    const tabs = [
      { type: 'page', url: 'https://chatgpt.com/c/original-thread', webSocketDebuggerUrl: 'ws://only' },
    ];
    let currentPath = '/c/original-thread';
    const assignments = [];
    const connector = async tab => {
      const cdp = fakeCdp({ pageText: 'normal reply' });
      cdp.marker = tab.webSocketDebuggerUrl;
      const originalEvaluate = cdp.evaluate.bind(cdp);
      cdp.evaluate = async expression => {
        const source = String(expression);
        if (source.includes('sidebar-latest-conversation')) return '/c/mobile-latest';
        if (source.trim() === 'location.pathname') return currentPath;
        if (source.includes('location.assign')) {
          assignments.push(source);
          currentPath = '/c/mobile-latest';
          return true;
        }
        return originalEvaluate(expression);
      };
      return cdp;
    };
    const selected = await connectReinforcementChatgptTab({
      tabs,
      connector,
      probeBanner: async () => false,
      allowCrossDeviceDiscovery: true,
    });
    const result = await alignLatestForReinforcement(
      selected,
      async () => ({ http_status: 429, source: 'filtered', item_present: false, item_keys: [] }),
    );
    assert.equal(result.action, 'navigated_sidebar_fallback');
    assert.equal(result.sidebar_fallback, true);
    assert.equal(result.restore_path, '/c/original-thread');
    assert.equal(assignments.length, 1, 'unique conversation tab may navigate once to the synced sidebar target');
    selected.close();
  }

  // A raw conversation CDP that was not proven unique must remain fail-closed.
  {
    let currentPath = '/c/unproven-thread';
    const cdp = fakeCdp({ pageText: 'normal reply' });
    const originalEvaluate = cdp.evaluate.bind(cdp);
    cdp.evaluate = async expression => {
      const source = String(expression);
      if (source.includes('sidebar-latest-conversation')) return '/c/mobile-latest';
      if (source.trim() === 'location.pathname') return currentPath;
      if (source.includes('location.assign')) {
        currentPath = '/c/mobile-latest';
        return true;
      }
      return originalEvaluate(expression);
    };
    const result = await alignLatestForReinforcement(
      cdp,
      async () => ({ http_status: 429, source: 'filtered', item_present: false, item_keys: [] }),
    );
    assert.equal(result.action, 'latest_unavailable');
    assert.equal(currentPath, '/c/unproven-thread', 'unproven conversation context must never be repurposed');
  }

  // Any temporary sidebar navigation must restore the original safe path even
  // when the discovered conversation has no interruption banner.
  {
    let currentPath = '/c/mobile-latest';
    const cdp = fakeCdp({ pageText: 'normal reply' });
    const originalEvaluate = cdp.evaluate.bind(cdp);
    cdp.evaluate = async expression => {
      const source = String(expression);
      if (source.trim() === 'location.pathname') return currentPath;
      if (source.includes('location.assign') && source.includes('/c/original-thread')) {
        currentPath = '/c/original-thread';
        return true;
      }
      return originalEvaluate(expression);
    };
    const result = await reinforcementCheckOnce(
      async () => cdp,
      1,
      async () => true,
      async () => ({
        action: 'navigated_sidebar_fallback',
        sidebar_fallback: true,
        restore_path: '/c/original-thread',
        http_status: 429,
      }),
      { allowCrossDeviceDiscovery: true },
    );
    assert.equal(result.action, 'no_banner');
    assert.equal(result.http_status, 429);
    assert.equal(currentPath, '/c/original-thread', 'temporary cross-device navigation must restore the original tab');
  }

  // Cross-device/iOS failures may not mirror the orange interruption banner
  // into the canonical VM browser. Use only canonical conversation metadata
  // to classify a silent stall: transport COMPLETE, current assistant node
  // explicitly end_turn=false, and no child continuation.
  {
    const silent = fakeCdp({ streamStatus: 'COMPLETE', pageText: 'normal reply' });
    const originalEvaluate = silent.evaluate.bind(silent);
    silent.evaluate = async expression => {
      const source = String(expression);
      if (source.includes('conversation-turn-state')) {
        return {
          http_status: 200,
          role: 'assistant',
          end_turn: false,
          child_count: 0,
          message_status: 'finished_successfully',
        };
      }
      return originalEvaluate(expression);
    };
    assert.equal(await silentStallPresent(silent), true);

    const completed = fakeCdp({ streamStatus: 'COMPLETE', pageText: 'normal reply' });
    const completedEvaluate = completed.evaluate.bind(completed);
    completed.evaluate = async expression => {
      const source = String(expression);
      if (source.includes('conversation-turn-state')) {
        return {
          http_status: 200,
          role: 'assistant',
          end_turn: true,
          child_count: 0,
          message_status: 'finished_successfully',
        };
      }
      return completedEvaluate(expression);
    };
    assert.equal(await silentStallPresent(completed), false, 'normal final assistant turn must never be classified as stalled');

    const active = fakeCdp({ streamStatus: 'IN_PROGRESS', pageText: 'normal reply' });
    const activeEvaluate = active.evaluate.bind(active);
    active.evaluate = async expression => {
      const source = String(expression);
      if (source.includes('conversation-turn-state')) {
        return {
          http_status: 200,
          role: 'assistant',
          end_turn: false,
          child_count: 0,
          message_status: 'in_progress',
        };
      }
      return activeEvaluate(expression);
    };
    assert.equal(await silentStallPresent(active), false, 'active transport must remain fail-closed');
  }

  // Reproduce the user's iPhone case: latest conversation is found with HTTP
  // 200, the VM has no interruption banner, but canonical metadata proves the
  // assistant turn ended silently. Reinforcement must reattach once, then send
  // only if the same silent-stall state persists.
  {
    const cdp = fakeCdp({ streamStatus: 'COMPLETE', pageText: 'normal reply', sendSucceeds: true });
    const originalEvaluate = cdp.evaluate.bind(cdp);
    let reloads = 0;
    cdp.evaluate = async expression => {
      const source = String(expression);
      if (source.includes('conversation-turn-state')) {
        return {
          http_status: 200,
          role: 'assistant',
          end_turn: false,
          child_count: 0,
          message_status: 'finished_successfully',
        };
      }
      if (source.includes('location.reload')) {
        reloads += 1;
        return true;
      }
      return originalEvaluate(expression);
    };
    let confirmCalls = 0;
    const result = await reinforcementCheckOnce(
      async () => cdp,
      1,
      async () => {
        confirmCalls += 1;
        return confirmCalls >= 2;
      },
      async () => ({ action: 'already_latest', http_status: 200 }),
      { allowCrossDeviceDiscovery: true },
    );
    assert.equal(result.action, 'confirmed_progress');
    assert.equal(result.sent, true);
    assert.equal(result.progress_confirmed, true);
    assert.equal(result.http_status, 200);
    assert.equal(result.cross_device_discovery, true);
    assert.equal(reloads, 1, 'silent-stall recovery must attempt exactly one passive reattach before send');
    assert.ok(cdp.calls.some(call => call.includes('b.click()')), 'persisting silent stall must send one bounded continuation');
  }

  // The default reinforcement discovery path must preserve a 429 status so
  // the scheduler can back off for minutes instead of self-amplifying.
  {
    const result = await alignLatestForReinforcement(
      fakeCdp({ pageText: 'normal reply' }),
      async () => ({ http_status: 429, source: 'filtered', item_present: false, item_keys: [] }),
    );
    assert.equal(result.action, 'latest_unavailable');
    assert.equal(result.http_status, 429);
  }

  {
    const normalDelay = reinforcementDiscoveryDelayMs({
      action: 'no_banner',
      cross_device_discovery: true,
    });
    const rateLimitedDelay = reinforcementDiscoveryDelayMs({
      action: 'navigated_sidebar_fallback',
      http_status: 429,
      cross_device_discovery: true,
    });
    assert.ok(normalDelay >= 60_000, 'cross-device discovery cadence must be measured in minutes');
    assert.ok(rateLimitedDelay >= 60_000, '429 backoff must be measured in minutes');
    assert.ok(rateLimitedDelay > normalDelay, '429 must back off longer than the normal discovery window');
    assert.equal(reinforcementDiscoveryDelayMs({ action: 'no_banner', cross_device_discovery: false }), 0);
  }

  // The reinforcement scheduler must call its check with exactly the public
  // five-argument contract. Extra positional arguments can silently replace
  // the options object in JavaScript and disable the 429 discovery backoff.
  {
    let receivedArgs = null;
    const stop = new Error('stop-after-one-reinforcement-iteration');
    await assert.rejects(
      () => reinforcementLoop(
        async (...args) => {
          receivedArgs = args;
          return { action: 'no_banner', cross_device_discovery: false };
        },
        () => 0,
        async () => { throw stop; },
      ),
      error => error === stop,
    );
    assert.equal(receivedArgs?.length, 5, 'reinforcement check contract must remain exactly five positional arguments');
    assert.deepEqual(
      receivedArgs?.[4],
      { allowCrossDeviceDiscovery: true },
      'the fifth argument must remain the options object, not a helper function',
    );
  }

  // The checkpoint-driven bridge loop and the reinforcement loop must start
  // independently. A slow reinforcement iteration cannot serialize the next
  // pollBridgeOnce cycle.
  {
    const events = [];
    let release;
    const blocked = new Promise(resolve => { release = resolve; });
    const running = mainLoop(
      async () => { events.push('bridge'); await blocked; },
      async () => { events.push('reinforcement'); await blocked; },
      true,
    );
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.deepEqual(events.sort(), ['bridge', 'reinforcement']);
    release();
    await running;
  }

  // Banner flashes then clears by the confirm re-check (client's own retry
  // succeeded) -> must NOT send a message.
  {
    let connectCalls = 0;
    const result = await reinforcementCheckOnce(async () => {
      connectCalls += 1;
      return fakeCdp({ pageText: connectCalls === 1 ? 'Streaming interrupted. Waiting for the complete message...' : 'Here is the finished answer.' });
    }, 1, async () => true, async () => ({ action: 'already_latest' }));
    assert.equal(result.action, 'self_resolved');
    assert.equal(connectCalls, 2, 'must re-check exactly once after the confirm delay');
  }

  // Banner still present on the confirm re-check -> must send.
  {
    const result = await reinforcementCheckOnce(
      async () => fakeCdp({ pageText: 'Transmissão interrompida. Aguardando a mensagem completa...' }),
      1,
      async () => true,
      async () => ({ action: 'already_latest' }),
    );
    assert.equal(result.action, 'confirmed_progress');
    assert.equal(result.sent, true);
    assert.equal(result.progress_confirmed, true);
  }

  // A click alone is NOT recovery. If no assistant output appears, keep the
  // episode retryable rather than treating it as success.
  {
    const result = await reinforcementCheckOnce(
      async () => fakeCdp({ pageText: 'Transmissão interrompida. Aguardando a mensagem completa...' }),
      1,
      async () => false,
      async () => ({ action: 'already_latest' }),
    );
    assert.equal(result.action, 'sent_unconfirmed');
    assert.equal(result.sent, true);
    assert.equal(result.progress_confirmed, false);
  }

  {
    const result = await reinforcementCheckOnce(
      async () => connectReinforcementChatgptTab({
        tabs: [
          { type: 'page', url: 'https://chatgpt.com/c/same-thread', webSocketDebuggerUrl: 'ws://same-a' },
          { type: 'page', url: 'https://chatgpt.com/c/same-thread', webSocketDebuggerUrl: 'ws://same-b' },
          { type: 'page', url: 'https://chatgpt.com/c/other-thread', webSocketDebuggerUrl: 'ws://other' },
        ],
        connector: async () => fakeCdp({ pageText: 'normal reply' }),
        probeBanner: async () => true,
        allowCrossDeviceDiscovery: true,
      }),
      1,
      async () => true,
      async () => ({ action: 'navigated', http_status: 200 }),
      { allowCrossDeviceDiscovery: true },
    );
    assert.equal(result.action, 'self_resolved');
  }

  console.log('reinforcementCheckOnce branches: PASS');
}

run().then(() => {
  console.log('CHATGPT_CONTINUITY_BRIDGE_WORKER_TEST=PASS');
}).catch(error => {
  console.error(error);
  process.exitCode = 1;
});
