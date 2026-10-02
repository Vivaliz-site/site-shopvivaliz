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
  recoverableFailureReason,
  outcomeStatusDetailCode,
  persistReinforcementHealth,
  bridgeResultPayload,
  transmissionErrorPresent,
  latestConversationProbe,
  latestConversationMeta,
  normalizeConversationCandidates,
  mergeRecentConversationCandidates,
  reinforcementSweepCandidates,
  alignToLatestConversation,
  alignLatestForReinforcement,
  assistantSnapshot,
  assistantProgressed,
  sameConversationSnapshot,
  confirmAssistantProgress,
  sendContinueMessage,
  attemptNudge,
  reinforcementCheckOnce,
  reinforcementDiscoveryDelayMs,
  reinforcementLoop,
  authorizationButtonTarget,
  clickAuthorizationIfPresent,
  authorizationCheckOnce,
  authorizationLoop,
  mainLoop,
  selectChatgptTab,
  safeConversationId,
  selectBoundConversationTabs,
  connectFirstUsableChatgptTab,
  connectReinforcementChatgptTab,
  resolveAmbiguousConversationTabs,
  selectCheckpointConversationCandidate,
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
      if (expression.includes('continuity-error-banner-probe')) {
        return /(something went wrong|algo deu errado|there was an error generating|houve um erro ao gerar|streaming interrupted|transmissão interrompida|transmissao interrompida|stopped thinking|parou de pensar|esgotou-se o tempo limite da solicitação|esgotou-se o tempo limite da solicitacao|request timed out|request timeout)/i.test(pageText);
      }
      if (expression.includes('continuity-transmission-error-probe')) {
        return /(erro na transmissão|erro na transmissao|error sending message|error in message transmission|message transmission error)/i.test(pageText);
      }
      if (expression.includes('continuity-request-timeout-probe')) {
        return /(esgotou-se o tempo limite da solicitação|esgotou-se o tempo limite da solicitacao|request timed out|request timeout)/i.test(pageText);
      }
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
    const bound = 'conversation_12345678';
    const errorPayload = bridgeResultPayload(
      'task-1',
      { result_status: 'ERROR', conversation_id: bound, detail: 'failed' },
      'failed',
    );
    assert.equal(errorPayload.conversation_id, undefined);
    const confirmedPayload = bridgeResultPayload(
      'task-1',
      { result_status: 'PROGRESS_CONFIRMED', conversation_id: bound, detail: 'ok' },
      'ok',
    );
    assert.equal(confirmedPayload.conversation_id, bound);
  }
  {
    const timeoutCdp = fakeCdp({ pageText: 'Esgotou-se o tempo limite da solicitação. Repetir' });
    assert.equal(await errorBannerPresent(timeoutCdp), true);
    assert.equal(await recoverableFailureReason(timeoutCdp), 'request_timeout');
    assert.equal(
      outcomeStatusDetailCode(
        'ERROR',
        'failure_class=RECOVERABLE_CHAT_FAILURE;failure_reason=request_timeout; request timed out',
      ),
      'RECOVERABLE_REQUEST_TIMEOUT',
    );
  }

  {
    assert.equal(
      outcomeStatusDetailCode('PROGRESS_CONFIRMED', 'continuation produced assistant progress'),
      'PROGRESS_CONFIRMED',
      'confirmed progress must never be logged as a runtime error',
    );
    assert.equal(
      outcomeStatusDetailCode('ERROR', 'conversation changed during recovery'),
      'CONVERSATION_CHANGED_DURING_RECOVERY',
      'error diagnostics must keep the existing detail classifier',
    );
  }

  {
    const tabs = [
      { type: 'page', webSocketDebuggerUrl: 'ws://a', url: 'https://chatgpt.com/c/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' },
      { type: 'page', webSocketDebuggerUrl: 'ws://b', url: 'https://chatgpt.com/c/11111111-2222-3333-4444-555555555555' },
      { type: 'page', webSocketDebuggerUrl: 'ws://home', url: 'https://chatgpt.com/' },
    ];
    assert.equal(safeConversationId('bad/id'), '');
    const bound = selectBoundConversationTabs(
      tabs,
      '11111111-2222-3333-4444-555555555555',
    );
    assert.equal(bound.length, 1);
    assert.equal(
      bound[0].url,
      'https://chatgpt.com/c/11111111-2222-3333-4444-555555555555',
      'explicit binding must select only the requested conversation',
    );
    assert.equal(
      selectBoundConversationTabs(tabs, '99999999-2222-3333-4444-555555555555').length,
      0,
      'missing explicit binding must fail closed rather than choose another tab',
    );
  }

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
    const checkpointAt = Date.now() - 8 * 60 * 1000;
    const selected = selectCheckpointConversationCandidate(
      [
        { id: 'task-target', update_time: (checkpointAt + 15_000) / 1000, source: 'project', project_id: 'g-p-one' },
        { id: 'other-recent', update_time: (checkpointAt + 4 * 60_000) / 1000, source: 'global' },
      ],
      checkpointAt,
    );
    assert.equal(selected?.id, 'task-target', 'checkpoint timestamp must bind a task to its own closest conversation');

    const ambiguous = selectCheckpointConversationCandidate(
      [
        { id: 'near-a', update_time: (checkpointAt + 20_000) / 1000, source: 'global' },
        { id: 'near-b', update_time: (checkpointAt + 40_000) / 1000, source: 'project', project_id: 'g-p-two' },
      ],
      checkpointAt,
    );
    assert.equal(ambiguous, null, 'near-tied conversations must fail closed instead of guessing');
  }

  {
    const now = Date.now();
    const checkpointAt = now - 6 * 60 * 1000;
    const home = { type: 'page', url: 'https://chatgpt.com/', webSocketDebuggerUrl: 'ws://home' };
    let navigatedId = '';
    const candidates = await resolveAmbiguousConversationTabs(
      [home],
      async () => ({ close() {} }),
      async () => ({
        http_status: 200,
        source: 'combined',
        id: 'newest-unrelated',
        update_time: now / 1000,
        candidates: [
          { id: 'newest-unrelated', update_time: now / 1000, source: 'global' },
          { id: 'checkpoint-target', update_time: (checkpointAt + 12_000) / 1000, source: 'project', project_id: 'g-p-target' },
        ],
      }),
      now,
      undefined,
      async (tab, id) => {
        navigatedId = id;
        tab.url = `https://chatgpt.com/c/${id}`;
        return true;
      },
      checkpointAt,
    );
    assert.equal(navigatedId, 'checkpoint-target', 'checkpoint recovery from a lone home tab must navigate to the intended thread');
    assert.equal(candidates.length, 1);
    assert.ok(candidates[0].url.endsWith('/c/checkpoint-target'));
  }

  {
    const now = Date.now();
    const checkpointAt = now - 20 * 60 * 1000;
    const tabs = [
      { type: 'page', url: 'https://chatgpt.com/c/task-target', webSocketDebuggerUrl: 'ws://target' },
      { type: 'page', url: 'https://chatgpt.com/c/newer-unrelated', webSocketDebuggerUrl: 'ws://newer' },
      { type: 'page', url: 'https://chatgpt.com/', webSocketDebuggerUrl: 'ws://home' },
    ];
    const candidates = await resolveAmbiguousConversationTabs(
      tabs,
      async () => ({ close() {} }),
      async () => ({
        http_status: 200,
        source: 'combined',
        id: 'newer-unrelated',
        update_time: now / 1000,
        candidates: [
          { id: 'newer-unrelated', update_time: now / 1000, source: 'global' },
          { id: 'task-target', update_time: (checkpointAt + 10_000) / 1000, source: 'project', project_id: 'g-p-one' },
        ],
      }),
      now,
      undefined,
      undefined,
      checkpointAt,
    );
    assert.deepEqual(
      candidates.map(tab => tab.webSocketDebuggerUrl),
      ['ws://target'],
      'task-specific checkpoint time must override an unrelated globally latest conversation',
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

  // A shared browser target can navigate while a recovery is confirming.
  // Growth on another thread, or loss of route identity, is never progress.
  {
    const baseline = { count: 1, lastText: 'old', conversationFingerprint: 'thread-a' };
    assert.equal(assistantProgressed(baseline,
      { count: 3, lastText: 'another answer', conversationFingerprint: 'thread-b' }), false);
    assert.equal(assistantProgressed(baseline,
      { count: 3, lastText: 'another answer' }), false);
    assert.equal(assistantProgressed(baseline,
      { count: 2, lastText: 'new answer', conversationFingerprint: 'thread-a' }), true);
    assert.equal(sameConversationSnapshot({ conversationFingerprint: '' },
      { conversationFingerprint: '' }), false, 'a home/login route cannot certify progress');
    const snapshot = await assistantSnapshot({ async evaluate() {
      return { count: 1, lastText: 'answer', conversationPath: '/c/synthetic-thread' };
    }});
    assert.match(snapshot.conversationFingerprint, /^[a-f0-9]{64}$/);
    assert.equal(Object.hasOwn(snapshot, 'conversationPath'), false, 'route identifiers stay out of snapshot metadata');
    const changedThread = { async evaluate() {
      return { count: 5, lastText: 'other', surfaceText: 'unrelated tool activity', conversationFingerprint: 'thread-b' };
    }};
    assert.equal(await confirmAssistantProgress(changedThread,
      { ...baseline, surfaceText: 'tool' }, 1100, 10), false,
      'neither assistant growth nor surface growth on another thread can confirm recovery');
  }

  {
    const cdp = fakeCdp({ generating: false });
    const evaluate = cdp.evaluate.bind(cdp);
    let snapshots = 0;
    cdp.evaluate = async expression => {
      if (String(expression).includes('conversationPath:String')) {
        snapshots += 1;
        return { count: 1, lastText: 'answer', conversationPath: snapshots === 1 ? '/c/thread-a' : '/c/thread-b' };
      }
      return evaluate(expression);
    };
    const result = await attemptNudge('thread-change', async () => cdp, async () => false);
    assert.equal(result.result_status, 'ERROR');
    assert.match(result.detail, /conversation changed during recovery/);
    assert.equal(result.sent, false, 'a route change during reattach must abort before continuation');
    assert.equal(cdp.calls.some(source => source.includes('proto.value') || source.includes('b.click()')), false);
  }
  {
    let probes = 0;
    const cdp = { async evaluate(source) {
      probes += 1;
      assert.match(String(source), /continuity-conversation-identity-probe/);
      return '/c/other-thread';
    }};
    assert.equal(await sendContinueMessage(cdp, 'expected-thread-hash'), false);
    assert.equal(probes, 1, 'mismatched identity must reject before any composer/input probe');
    const expected = await assistantSnapshot({ async evaluate() {
      return { conversationPath: '/c/thread-a' };
    }});
    let composerProbes = 0;
    await sendContinueMessage({ async evaluate(source) {
      if (String(source).includes('continuity-conversation-identity-probe')) {
        return Function('location', 'return ' + source)({ pathname: '/c/thread-a' });
      }
      composerProbes += 1;
      return false;
    }}, expected.conversationFingerprint);
    assert.equal(composerProbes, 1, 'matching identity expression must parse and permit the composer probe');
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
    assert.match(expressionSeen, /turn-action-controls/, 'current UI assistant actions must be a semantic fallback');
    assert.match(expressionSeen, /querySelector\(['"]main['"]\)/, 'current UI main surface must be captured for progress fallback');
  }
  assert.equal(
    assistantProgressed(
      { count: 1, lastText: 'a much longer previous answer', lastLength: 29, lastKey: 'turn-old' },
      { count: 1, lastText: 'ok', lastLength: 2, lastKey: 'turn-new' },
    ),
    true,
    'a new assistant turn key must confirm progress even when virtualization keeps count stable and the new answer is shorter',
  );

  {
    let snapshots = 0;
    const cdp = {
      async evaluate(expression) {
        const source = String(expression);
        if (source.includes('data-message-author-role')) {
          snapshots += 1;
          return {
            count: 0,
            lastText: '',
            lastLength: 0,
            lastKey: '',
            surfaceText: snapshots >= 1 ? 'tool activity advanced' : 'tool activity',
            surfaceLength: snapshots >= 1 ? 22 : 13,
          };
        }
        if (source.includes('stop-button')) return true;
        return null;
      },
    };
    assert.equal(
      await confirmAssistantProgress(
        cdp,
        { count: 0, lastText: '', lastLength: 0, lastKey: '', surfaceText: 'tool activity', surfaceLength: 13 },
        1100,
        10,
      ),
      true,
      'current UI main-surface growth must confirm assistant/tool progress when legacy turn selectors are absent',
    );
  }

  {
    const cdp = fakeCdp({ generating: false, sendSucceeds: true });
    const originalEvaluate = cdp.evaluate.bind(cdp);
    let snapshotNumber = 0;
    cdp.evaluate = async expression => {
      const source = String(expression);
      if (source.includes('data-message-author-role')) {
        snapshotNumber += 1;
        return {
          count: 0,
          lastText: '',
          lastLength: 0,
          lastKey: '',
          surfaceText: snapshotNumber >= 3 ? 'after-own-continue' : (snapshotNumber === 2 ? 'before-send' : 'before-reattach'),
          surfaceLength: snapshotNumber >= 3 ? 18 : (snapshotNumber === 2 ? 11 : 15),
        };
      }
      return originalEvaluate(expression);
    };
    let confirmCalls = 0;
    const result = await attemptNudge(
      'task-post-send-baseline',
      async () => cdp,
      async (_connected, baseline) => {
        confirmCalls += 1;
        if (confirmCalls === 1) return false;
        assert.equal(
          baseline?.surfaceText,
          'after-own-continue',
          'progress confirmation must start from a post-send snapshot so the continue message itself is not counted',
        );
        return true;
      },
      async () => true,
    );
    assert.equal(result.result_status, 'PROGRESS_CONFIRMED');
    assert.ok(snapshotNumber >= 3, 'attemptNudge must capture a post-send confirmation baseline');
  }

  {
    const cdp = fakeCdp({ generating: false, composerUsable: true });
    const originalEvaluate = cdp.evaluate.bind(cdp);
    cdp.evaluate = async expression => {
      if (String(expression) === 'location.pathname') {
        return '/c/12345678-2222-3333-4444-555555555555';
      }
      return originalEvaluate(expression);
    };
    const outcome = await attemptNudge(
      'task-binding-discovery',
      async () => cdp,
      async () => true,
      async () => true,
    );
    assert.equal(outcome.result_status, 'PROGRESS_CONFIRMED');
    assert.equal(
      outcome.conversation_id,
      '12345678-2222-3333-4444-555555555555',
      'confirmed recovery must report the exact conversation it acted on',
    );
  }

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
      await waitForComposerUsable(cdp, 1000, 10),
      true,
      'transient post-reattach composer absence must recover within a bounded wait even with scheduler jitter',
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
  {
    const calls = [];
    let draft = 'continue';
    let selectedAll = false;
    let sendEnabled = false;
    const cdp = {
      async evaluate(expression) {
        const source = String(expression);
        calls.push(['evaluate', source]);
        if (source.includes('continuity-composer-draft-probe')) {
          return { usable: true, text: draft };
        }
        if (source.includes('continuity-composer-click-target')) return { x: 320, y: 640 };
        if (source.includes('continuity-composer-focus')) return true;
        if (source.includes('continuity-send-button-target')) {
          return sendEnabled ? { state: 'ready', x: 700, y: 640 } : { state: 'disabled' };
        }
        if (source.includes('continuity-send-button-click')) return sendEnabled;
        return false;
      },
      async send(method, params = {}) {
        calls.push(['send', method, params]);
        if (method !== 'Input.dispatchKeyEvent') return {};
        if (params.type === 'rawKeyDown' && params.key === 'a' && Number(params.modifiers || 0) === 2) {
          selectedAll = true;
        } else if (params.type === 'rawKeyDown' && params.key === 'Backspace' && selectedAll) {
          draft = '';
          selectedAll = false;
          sendEnabled = false;
        } else if (params.type === 'char' && typeof params.text === 'string') {
          draft += params.text;
          if (draft.trim() === 'continue') sendEnabled = true;
        }
        return {};
      },
    };
    assert.equal(
      await sendContinueMessage(cdp),
      true,
      'production CDP path must clear stale continuation text and retype it with trusted key events',
    );
    const typedChars = calls
      .filter(call => call[0] === 'send' && call[1] === 'Input.dispatchKeyEvent' && call[2]?.type === 'char')
      .map(call => String(call[2]?.text || ''))
      .join('');
    assert.equal(typedChars, 'continue');
    assert.ok(
      calls.some(call => call[0] === 'send' && call[2]?.type === 'rawKeyDown' && call[2]?.key === 'Backspace'),
      'trusted path must clear stale untrusted continuation text before retyping',
    );
    const firstCharIndex = calls.findIndex(
      call => call[0] === 'send'
        && call[1] === 'Input.dispatchKeyEvent'
        && call[2]?.type === 'char',
    );
    const composerMousePressIndex = calls.findIndex(
      call => call[0] === 'send'
        && call[1] === 'Input.dispatchMouseEvent'
        && call[2]?.type === 'mousePressed',
    );
    const sendMousePressIndex = calls.findIndex(
      (call, index) => index > firstCharIndex
        && call[0] === 'send'
        && call[1] === 'Input.dispatchMouseEvent'
        && call[2]?.type === 'mousePressed',
    );
    assert.ok(
      composerMousePressIndex >= 0 && composerMousePressIndex < firstCharIndex,
      'production path must use a trusted CDP mouse click in the composer before typing',
    );
    assert.ok(
      sendMousePressIndex > firstCharIndex,
      'production path must use a trusted CDP mouse click on the enabled Send control',
    );
    assert.ok(
      calls.some(call => call[0] === 'evaluate' && String(call[1]).includes('continuity-send-button-target')),
      'trusted path must resolve the real enabled Send geometry before submitting',
    );
  }

  {
    const calls = [];
    const cdp = {
      async evaluate(expression) {
        const source = String(expression);
        calls.push(['evaluate', source]);
        if (source.includes('continuity-composer-draft-probe')) {
          return { usable: true, text: 'continue' };
        }
        if (source.includes('continuity-composer-click-target')) return { x: 320, y: 640 };
        if (source.includes('continuity-composer-focus')) return false;
        if (source.includes('continuity-send-button-target')) {
          return { state: 'ready', x: 700, y: 640 };
        }
        if (source.includes('continuity-submit-observed')) return true;
        return false;
      },
      async send(method, params = {}) {
        calls.push(['send', method, params]);
        return {};
      },
    };
    assert.equal(
      await sendContinueMessage(cdp),
      true,
      'a safe stale continue draft with enabled Send must remain submit-capable even when ProseMirror refuses activeElement focus',
    );
    assert.ok(
      calls.some(call => call[0] === 'evaluate' && String(call[1]).includes('continuity-send-button-target')),
      'stale safe draft must resolve Send geometry before giving up on focus',
    );
    const mousePresses = calls.filter(
      call => call[0] === 'send'
        && call[1] === 'Input.dispatchMouseEvent'
        && call[2]?.type === 'mousePressed',
    );
    assert.ok(
      mousePresses.length >= 2,
      'worker must attempt composer click and then trusted Send click for the already-safe continuation draft',
    );
  }

  {
    const calls = [];
    let draft = '';
    const cdp = {
      async evaluate(expression) {
        const source = String(expression);
        calls.push(['evaluate', source]);
        if (source.includes('continuity-composer-draft-probe')) {
          return { usable: true, text: draft };
        }
        if (source.includes('continuity-composer-click-target')) return { x: 320, y: 640 };
        if (source.includes('continuity-composer-focus')) return false;
        if (source.includes('continuity-composer-draft-after-trusted-insert')) return draft;
        if (source.includes('continuity-send-button-target')) {
          return draft === 'continue'
            ? { state: 'ready', x: 700, y: 640 }
            : { state: 'disabled' };
        }
        return false;
      },
      async send(method, params = {}) {
        calls.push(['send', method, params]);
        if (method === 'Input.insertText') draft += String(params.text || '');
        return {};
      },
    };
    assert.equal(
      await sendContinueMessage(cdp),
      true,
      'an empty composer must recover through trusted insert when ProseMirror leaves BODY focused',
    );
    assert.equal(draft, 'continue');
    assert.ok(
      calls.some(call => call[0] === 'send' && call[1] === 'Input.insertText' && call[2]?.text === 'continue'),
      'BODY-focus recovery must use trusted CDP text insertion',
    );
    assert.ok(
      calls.some(call => call[0] === 'evaluate' && String(call[1]).includes('continuity-composer-draft-after-trusted-insert')),
      'worker must read the composer back before submitting the inserted continuation',
    );
    assert.ok(
      calls.some(call => call[0] === 'send' && call[1] === 'Input.dispatchMouseEvent' && call[2]?.type === 'mousePressed'),
      'verified continuation must be submitted through a trusted pointer event',
    );
  }

  {
    let sendCalls = 0;
    const cdp = {
      async evaluate(expression) {
        if (String(expression).includes('continuity-composer-draft-probe')) {
          return { usable: true, text: 'unsent customer draft' };
        }
        return false;
      },
      async send() {
        sendCalls += 1;
        return {};
      },
    };
    assert.equal(
      await sendContinueMessage(cdp),
      false,
      'continuity worker must never overwrite a non-continuation user draft',
    );
    assert.equal(sendCalls, 0);
  }

  {
    const calls = [];
    const cdp = {
      async evaluate(expression) {
        calls.push(['evaluate', expression]);
        if (expression.includes('insertText') || expression.includes('proto.value')) return true;
        if (expression.includes('b.click()')) return false;
        return false;
      },
      async send(method, params) {
        calls.push(['send', method, params]);
        return {};
      },
    };
    assert.equal(
      await sendContinueMessage(cdp),
      true,
      'missing send button after typing must fall back to a real Enter key dispatch',
    );
    assert.ok(
      calls.some(call => call[0] === 'send' && call[1] === 'Input.dispatchKeyEvent' && call[2]?.key === 'Enter'),
      'Enter fallback must use CDP Input.dispatchKeyEvent',
    );
  }
  {
    let evaluateCalls = 0;
    const cdp = {
      async pageState() {
        return { href: 'https://chatgpt.com/c/long', title: 'ChatGPT', text: 'x'.repeat(6000) };
      },
      async evaluate(expression) {
        evaluateCalls += 1;
        if (String(expression).includes('continuity-error-banner-probe')) return true;
        return false;
      },
    };
    assert.equal(
      await errorBannerPresent(cdp),
      true,
      'error detection must not depend on the first 6000 pageState characters in long conversations',
    );
    assert.ok(evaluateCalls > 0, 'error detection must use a targeted DOM boolean probe');
  }

  {
    let evaluateCalls = 0;
    const cdp = {
      async pageState() {
        return { href: 'https://chatgpt.com/c/long', title: 'ChatGPT', text: 'x'.repeat(6000) };
      },
      async evaluate(expression) {
        evaluateCalls += 1;
        if (String(expression).includes('continuity-transmission-error-probe')) return true;
        return false;
      },
    };
    assert.equal(
      await transmissionErrorPresent(cdp),
      true,
      'transmission-error detection must survive long conversations without pageState truncation',
    );
    assert.ok(evaluateCalls > 0, 'transmission detection must use a targeted DOM boolean probe');
  }

  {
    const cdp = {
      async pageState() {
        return { href: 'https://chatgpt.com/c/history', title: 'ChatGPT', text: 'Parou de pensar\nold historical turn\nhealthy current answer' };
      },
      async evaluate(expression) {
        if (String(expression).includes('continuity-error-banner-probe')) return false;
        return false;
      },
    };
    assert.equal(
      await errorBannerPresent(cdp),
      false,
      'historical stopped-thinking text outside the current DOM scope must not retrigger recovery',
    );
  }

  {
    const cdp = {
      async pageState() {
        return { href: 'https://chatgpt.com/c/history', title: 'ChatGPT', text: 'Erro na transmissão de mensagem\nold historical turn\nhealthy current answer' };
      },
      async evaluate(expression) {
        if (String(expression).includes('continuity-transmission-error-probe')) return false;
        return false;
      },
    };
    assert.equal(
      await transmissionErrorPresent(cdp),
      false,
      'historical transmission-error text outside the current DOM scope must not retrigger recovery',
    );
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
  {
    const marker = 'nossos sistemas estão fazendo verificações adicionais';
    const cdp = {
      async evaluate(expression) {
        const source = String(expression).toLowerCase();
        return source.includes('continuity-error-banner-probe') && source.includes(marker);
      },
    };
    assert.equal(
      await errorBannerPresent(cdp),
      true,
      'the explicit additional-checks terminal state must be classified as a recoverable ChatGPT failure',
    );
  }
  {
    const marker = 'try again with a faster model';
    const cdp = {
      async evaluate(expression) {
        const source = String(expression).toLowerCase();
        return source.includes('continuity-error-banner-probe') && source.includes(marker);
      },
    };
    assert.equal(
      await errorBannerPresent(cdp),
      true,
      'the faster-model fallback copy must be recognized without switching models automatically',
    );
  }
  {
    const cdp = {
      async evaluate(expression) {
        const source = String(expression);
        if (source.includes('continuity-error-banner-probe')) return true;
        if (source.includes('continuity-additional-checks-probe')) return true;
        return false;
      },
    };
    assert.equal(
      await recoverableFailureReason(cdp),
      'additional_checks',
      'additional-checks UI must emit the sanitized failure_reason used by recovery telemetry',
    );
  }
  assert.equal(await transmissionErrorPresent(fakeCdp({ pageText: 'Erro na transmissão de mensagem' })), true);
  assert.equal(await transmissionErrorPresent(fakeCdp({ pageText: 'normal completed answer' })), false);

  console.log('conversationIsGenerating/composerIsUsable/errorBannerPresent/transmissionErrorPresent: PASS');

  {
    const cdp = fakeCdp({
      pageText: 'Parou de pensar',
      generating: true,
      composerUsable: true,
      sendSucceeds: true,
      streamStatus: 'IN_PROGRESS',
      staleStopClearSucceeds: true,
    });
    const outcome = await attemptNudge(
      'task-terminal-banner-with-stale-stop',
      async () => cdp,
      async () => false,
      async () => true,
    );
    assert.notEqual(
      outcome.result_status,
      'STALLED_NOT_CONFIRMED',
      'an explicit recoverable failure must outrank a stale Stop control / IN_PROGRESS bookkeeping signal',
    );
  }

  {
    let connects = 0;
    const failed = fakeCdp({ pageText: 'Parou de pensar', generating: false });
    const clearedWithoutProgress = fakeCdp({
      pageText: '',
      generating: false,
      composerUsable: true,
      sendSucceeds: true,
      streamStatus: 'COMPLETE',
    });
    const outcome = await reinforcementCheckOnce(
      async () => {
        connects += 1;
        return connects === 1 ? failed : clearedWithoutProgress;
      },
      0,
      async () => false,
      async () => ({ action: 'unused' }),
      { allowCrossDeviceDiscovery: false },
    );
    assert.notEqual(
      outcome.action,
      'self_resolved',
      'a recoverable failure that disappears from the DOM without assistant progress must never be declared self-resolved',
    );
  }

  {
    const idle = await attemptNudge('idle-reattach-hydration-proof', async () => fakeCdp(), async () => true);
    assert.equal(
      idle.sent,
      true,
      'idle DOM growth after reload must not be accepted as passive assistant progress',
    );
    const active = await attemptNudge('active-send-proof', async () => fakeCdp({generating: true}), async () => false);
    assert.equal(active.sent, false, 'deferred active generation cannot claim a send');
    const failed = await attemptNudge('failed-send-proof', async () => fakeCdp({sendSucceeds: false}), async () => false);
    assert.equal(failed.sent, false, 'failed composer submission cannot claim a send');
    let progressChecks = 0;
    const sent = await attemptNudge('successful-send-proof', async () => fakeCdp(), async () => ++progressChecks > 1);
    assert.equal(sent.sent, true, 'successful trusted send records the actual effect');
  }

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

  // Project conversations use the same /c/<id> route but are discovered from
  // /backend-api/gizmos/g-p-.../conversations. Continuity must aggregate both
  // global and Project sources instead of assuming /backend-api/conversations
  // is the complete account view.
  {
    const now = Math.floor(Date.now() / 1000);
    const cdp = fakeCdp();
    cdp.evaluate = async expression => {
      cdp.calls.push(expression);
      assert.ok(expression.includes('/backend-api/gizmos/'));
      assert.ok(expression.includes('g-p-'));
      assert.ok(expression.includes('/conversations'));
      assert.ok(expression.includes('AbortSignal.timeout'), 'every discovery request must have a bounded timeout');
      assert.ok(expression.includes('a[href*="g-p-"]'), 'Project ids must also be discovered from loaded sidebar links');
      assert.ok(expression.includes('Promise.allSettled'), 'Project conversation requests must be bounded and batched instead of serial');
      return {
        http_status: 200,
        source: 'combined',
        item_present: true,
        item_keys: ['id', 'update_time'],
        id: 'project-latest-thread',
        update_time: now,
        candidates: [
          { id: 'global-older-thread', update_time: now - 30, source: 'global' },
          { id: 'project-latest-thread', update_time: now, source: 'project', project_id: 'g-p-project-one' },
        ],
      };
    };
    const probe = await latestConversationProbe(cdp);
    assert.equal(probe.candidates.length, 2);
  }

  {
    const now = Math.floor(Date.now() / 1000);
    const normalized = normalizeConversationCandidates({
      candidates: [
        { id: 'global-older-thread', update_time: now - 40, source: 'global' },
        { id: 'project-newer-thread', update_time: now - 5, source: 'project', project_id: 'g-p-project-one' },
        { id: 'project-newer-thread', update_time: now - 10, source: 'project', project_id: 'g-p-project-one' },
      ],
    });
    assert.deepEqual(
      normalized.map(x => x.id),
      ['project-newer-thread', 'global-older-thread'],
      'candidate normalization must deduplicate and sort Project/global chats by recency',
    );
  }

  {
    const nowMs = Date.now();
    let currentPath = '/c/original-thread';
    const cdp = fakeCdp();
    const originalEvaluate = cdp.evaluate.bind(cdp);
    cdp.evaluate = async expression => {
      const source = String(expression);
      if (source.trim() === 'location.pathname') return currentPath;
      if (source.includes('location.assign')) {
        if (source.includes('project-newer-thread')) currentPath = '/c/project-newer-thread';
        return true;
      }
      return originalEvaluate(expression);
    };
    const result = await alignLatestForReinforcement(
      cdp,
      async () => ({
        http_status: 200,
        source: 'combined',
        candidates: [
          { id: 'global-older-thread', update_time: (nowMs - 30_000) / 1000, source: 'global' },
          { id: 'project-newer-thread', update_time: (nowMs - 2_000) / 1000, source: 'project', project_id: 'g-p-project-one' },
        ],
      }),
      nowMs,
    );
    assert.equal(result.action, 'navigated');
    assert.equal(currentPath, '/c/project-newer-thread');
  }

  // Real iPhone reproduction 2026-10-01: the UI remained on "Parou de pensar"
  // roughly 13 minutes after the last user-turn update. Reinforcement must
  // still inspect that exact latest conversation; the old 10-minute default
  // silently classified it stale before the explicit failure banner was read.
  {
    const nowMs = Date.now();
    let currentPath = '/';
    const cdp = fakeCdp();
    const originalEvaluate = cdp.evaluate.bind(cdp);
    cdp.evaluate = async expression => {
      const source = String(expression);
      if (source.trim() === 'location.pathname') return currentPath;
      if (source.includes('location.assign')) {
        currentPath = '/c/thirteen-minute-stall';
        return true;
      }
      return originalEvaluate(expression);
    };
    const recoverable = await alignLatestForReinforcement(
      cdp,
      async () => ({
        http_status: 200,
        project_count: 0,
        candidates: [
          {
            id: 'thirteen-minute-stall',
            update_time: (nowMs - 13 * 60_000) / 1000,
            source: 'global',
          },
        ],
      }),
      nowMs,
    );
    assert.equal(recoverable.action, 'navigated');
    assert.equal(recoverable.latest_age_seconds >= 13 * 60 - 2, true);
    assert.equal(recoverable.candidate_count, 1);

    const tooOld = await alignLatestForReinforcement(
      cdp,
      async () => ({
        http_status: 200,
        project_count: 0,
        candidates: [
          {
            id: 'thirty-one-minute-old',
            update_time: (nowMs - 31 * 60_000) / 1000,
            source: 'global',
          },
        ],
      }),
      nowMs,
    );
    assert.equal(tooOld.action, 'stale_latest');
  }

  {
    const now = Math.floor(Date.now() / 1000);
    const merged = mergeRecentConversationCandidates(
      [
        { id: 'global-latest', update_time: now, source: 'global' },
        { id: 'project-a', update_time: now - 10, source: 'project', project_id: 'g-p-a' },
      ],
      [
        { id: 'project-b', update_time: now - 20, source: 'project', project_id: 'g-p-b' },
        { id: 'project-a', update_time: now - 5, source: 'project', project_id: 'g-p-a' },
      ],
      now * 1000,
      30 * 60 * 1000,
    );
    assert.deepEqual(merged.map(x => x.id), ['global-latest', 'project-a', 'project-b']);

    const first = reinforcementSweepCandidates(merged, 'global-latest', 0, 2);
    assert.deepEqual(first.batch.map(x => x.id), ['project-a', 'project-b']);
    assert.equal(first.next_cursor, 0, 'two-item non-latest sweep should wrap cleanly');
  }

  console.log('cross-device latest-conversation alignment: PASS');

  // Live canonical reproduction 2026-09-30: stream_status can be COMPLETE
  // while current_node ends in assistant end_turn=false with no child and the
  // UI exposes no Stop button. A silent stall must therefore receive the same
  // passive reattach/reload before any continuation send.
  {
    const silentCdp = fakeCdp({
      generating: false,
      composerUsable: false,
      sendSucceeds: true,
      streamStatus: 'IN_PROGRESS',
    });
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
      if (expression.includes('continuity-transmission-error-probe')) {
        return /erro na transmissão de mensagem/i.test(pageText);
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
    const persistentEvaluate = persistent.evaluate.bind(persistent);
    persistent.evaluate = async expression => {
      if (expression.includes('continuity-transmission-error-probe')) return true;
      return persistentEvaluate(expression);
    };
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

  {
    let reloads = 0;
    let composerReady = false;
    const recoverSendCdp = fakeCdp({ sendSucceeds: true });
    const originalEvaluate = recoverSendCdp.evaluate.bind(recoverSendCdp);
    recoverSendCdp.evaluate = async expression => {
      if (expression.includes('prompt-textarea') && !expression.includes('insertText') && !expression.includes('b.click()')) {
        return composerReady;
      }
      if (expression.includes('location.reload')) {
        reloads += 1;
        composerReady = true;
      }
      if (expression.includes('insertText') || expression.includes('proto.value')) {
        return true;
      }
      if (expression.includes('b.click()')) return true;
      return originalEvaluate(expression);
    };
    let confirms = 0;
    const recoveredSend = await attemptNudge(
      'task-send-false-recovery',
      async () => recoverSendCdp,
      async () => ++confirms >= 2,
      async () => composerReady,
    );
    assert.equal(recoveredSend.result_status, 'PROGRESS_CONFIRMED');
    assert.ok(reloads >= 1, 'send=false must trigger bounded reattach');
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
      cdp.currentPath = ({
        'ws://a': '/c/open-alpha',
        'ws://b': '/c/open-bravo',
        'ws://c': '/c/open-charlie',
        'ws://d': '/c/open-delta',
      })[cdp.marker] || '/';
      cdp.evaluate = async expression => {
        const source = String(expression);
        if (source.trim() === 'location.pathname') return cdp.currentPath;
        if (source.includes('sidebar-latest-conversation')) {
          return sidebarLatestByMarker.get(cdp.marker) || '';
        }
        if (source.includes('location.assign')) {
          const match = source.match(/location\.assign\(("[^"]+")\)/);
          if (match) cdp.currentPath = JSON.parse(match[1]);
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
    assert.equal(
      selected.marker,
      'ws://a',
      'sidebar disagreement must not block account-scoped discovery; choose the first idle context deterministically',
    );

    const apiAligned = await alignLatestForReinforcement(
      selected,
      async () => ({
        http_status: 200,
        source: 'filtered',
        item_present: true,
        id: 'mobile-latest-123',
        update_time: Date.now() / 1000,
      }),
      Date.now(),
    );
    assert.equal(apiAligned.action, 'navigated');
    assert.equal(apiAligned.http_status, 200);
    assert.equal(apiAligned.restore_path, '/c/open-alpha');
    assert.equal(selected.currentPath, '/c/mobile-latest-123');

    // The same 2-2 sidebar split must still fail closed under 429: the
    // deterministic idle context is safe for account-scoped discovery, but
    // not automatically trusted as a sidebar fallback.
    selected.currentPath = '/c/open-alpha';
    const rateLimited = await alignLatestForReinforcement(
      selected,
      async () => ({ http_status: 429, source: 'filtered', item_present: false, item_keys: [] }),
    );
    assert.equal(rateLimited.action, 'latest_unavailable');
    assert.equal(rateLimited.http_status, 429);
    selected.close();
  }

  {
    const tabs = [
      { type: 'page', url: 'https://chatgpt.com/c/busy-alpha', webSocketDebuggerUrl: 'ws://busy-a' },
      { type: 'page', url: 'https://chatgpt.com/c/busy-bravo', webSocketDebuggerUrl: 'ws://busy-b' },
    ];
    const connector = async tab => {
      const cdp = fakeCdp({ pageText: 'normal reply', generating: true });
      cdp.marker = tab.webSocketDebuggerUrl;
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
      'all active conversation tabs must remain fail-closed because none is safe to repurpose as a discovery context',
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

  // Once cross-device discovery has aligned an exact target, the delayed
  // confirmation must stay on that same CDP. Re-running tab selection here can
  // jump to a different conversation before the failure is confirmed.
  {
    let connectCalls = 0;
    let confirmChecks = 0;
    let pageText = 'normal reply';
    const cdp = fakeCdp({ pageText: '' });
    const originalEvaluate = cdp.evaluate.bind(cdp);
    cdp.evaluate = async expression => {
      if (String(expression).includes('continuity-error-banner-probe')) {
        return /streaming interrupted/i.test(pageText);
      }
      return originalEvaluate(expression);
    };
    cdp.pageState = async () => ({ href: 'https://chatgpt.com/c/mobile-latest-123', title: 'ChatGPT', text: pageText });
    const result = await reinforcementCheckOnce(
      async () => {
        connectCalls += 1;
        return cdp;
      },
      1,
      async () => ++confirmChecks > 1,
      async () => {
        pageText = 'Streaming interrupted. Waiting for the complete message...';
        return { action: 'navigated', http_status: 200, restore_path: '/c/original-thread' };
      },
      { allowCrossDeviceDiscovery: true },
    );
    assert.equal(result.action, 'confirmed_progress');
    assert.equal(result.sent, true);
    assert.equal(result.progress_confirmed, true);
    assert.equal(connectCalls, 1, 'cross-device aligned target must remain on the same CDP through delayed confirmation');
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

  // Live reproduction 2026-09-30: /stream_status returns 200 while the
  // conversation metadata endpoint returns 404 when the account context is
  // omitted. The turn-state probe must use the same authenticated account
  // headers as latestConversationProbe.
  {
    let expression = '';
    const cdp = {
      async evaluate(source) {
        expression = String(source);
        return {
          http_status: 200,
          role: 'assistant',
          end_turn: false,
          child_count: 0,
          message_status: 'finished_successfully',
        };
      },
    };
    const state = await conversationTurnState(cdp, 25);
    assert.equal(state.http_status, 200);
    assert.match(expression, /\/api\/auth\/session/, 'turn-state probe must load the authenticated ChatGPT session');
    assert.match(expression, /Authorization/, 'turn-state probe must forward the bearer token when available');
    assert.match(expression, /ChatGPT-Account-Id/, 'turn-state probe must bind the request to the active ChatGPT account');
  }

  // Current UI fallback when the canonical conversation metadata endpoint is
  // rate-limited/unavailable: a COMPLETE transport whose last data-turn-key
  // contains user-message controls but no assistant node/action controls is a
  // locally observable unfinished turn. This fallback is never used when the
  // canonical turn-state request succeeds.
  {
    const fallback = fakeCdp({ streamStatus: 'COMPLETE', pageText: 'normal reply' });
    const originalEvaluate = fallback.evaluate.bind(fallback);
    fallback.evaluate = async expression => {
      const source = String(expression);
      if (source.includes('conversation-turn-state')) {
        return {
          http_status: 429,
          role: '',
          end_turn: null,
          child_count: -1,
          message_status: 'HTTP_ERROR',
        };
      }
      if (source.includes('local-incomplete-turn')) return true;
      return originalEvaluate(expression);
    };
    assert.equal(
      await silentStallPresent(fallback),
      true,
      'rate-limited canonical metadata must fall back to a locally unfinished last turn',
    );
  }

  {
    const completedLocal = fakeCdp({ streamStatus: 'COMPLETE', pageText: 'normal reply' });
    const originalEvaluate = completedLocal.evaluate.bind(completedLocal);
    completedLocal.evaluate = async expression => {
      const source = String(expression);
      if (source.includes('conversation-turn-state')) {
        return {
          http_status: 429,
          role: '',
          end_turn: null,
          child_count: -1,
          message_status: 'HTTP_ERROR',
        };
      }
      if (source.includes('local-incomplete-turn')) return false;
      return originalEvaluate(expression);
    };
    assert.equal(
      await silentStallPresent(completedLocal),
      false,
      'completed local turn must remain fail-closed while canonical metadata is unavailable',
    );
  }

  {
    const canonicalComplete = fakeCdp({ streamStatus: 'COMPLETE', pageText: 'normal reply' });
    const originalEvaluate = canonicalComplete.evaluate.bind(canonicalComplete);
    let localFallbackCalled = false;
    canonicalComplete.evaluate = async expression => {
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
      if (source.includes('local-incomplete-turn')) {
        localFallbackCalled = true;
        return true;
      }
      return originalEvaluate(expression);
    };
    assert.equal(await silentStallPresent(canonicalComplete), false);
    assert.equal(localFallbackCalled, false, 'canonical 200 result must suppress the local heuristic');
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

  // A 429 must back off only the account-scoped API, not the local sidebar
  // inspection. The live iPhone failure on 2026-09-30 appeared ~3 minutes
  // after a no_banner+429 cycle; suppressing all cross-device inspection for
  // five minutes created a blind window.
  {
    const calls = [];
    let waits = 0;
    const stop = new Error('stop-after-two-reinforcement-iterations');
    await assert.rejects(
      () => reinforcementLoop(
        async (...args) => {
          calls.push(args);
          return calls.length === 1
            ? {
                action: 'no_banner',
                http_status: 429,
                cross_device_discovery: true,
              }
            : {
                action: 'no_banner',
                http_status: 0,
                cross_device_discovery: true,
              };
        },
        () => 0,
        async () => {
          waits += 1;
          if (waits >= 2) throw stop;
        },
      ),
      error => error === stop,
    );
    assert.equal(calls.length, 2, 'second reinforcement cycle must still run during API backoff');
    assert.equal(calls[0]?.[3], alignLatestForReinforcement, 'first cycle may use the account-scoped API');
    assert.notEqual(
      calls[1]?.[3],
      alignLatestForReinforcement,
      'cycle inside API backoff must switch to local sidebar alignment',
    );
    assert.deepEqual(
      calls[1]?.[4],
      { allowCrossDeviceDiscovery: true },
      'local sidebar discovery must stay enabled while API discovery is backed off',
    );
  }

  // Live reproduction 2026-09-30 23:22 BRT: server-side stream state can
  // remain IS_STREAMING while the canonical browser has neither a Stop/
  // generating signal nor a usable composer. That detached shape is a stall
  // candidate; active DOM generation or a usable composer must stay fail-closed.
  {
    const orphaned = fakeCdp({
      generating: false,
      composerUsable: false,
      streamStatus: 'IS_STREAMING',
      pageText: 'normal reply',
    });
    assert.equal(
      await silentStallPresent(orphaned),
      true,
      'IS_STREAMING without DOM generation or composer must be a stall candidate',
    );

    const active = fakeCdp({
      generating: true,
      composerUsable: false,
      streamStatus: 'IS_STREAMING',
      pageText: 'normal reply',
    });
    assert.equal(
      await silentStallPresent(active),
      false,
      'an actively generating DOM must never be classified as orphaned',
    );

    const usableComposer = fakeCdp({
      generating: false,
      composerUsable: true,
      streamStatus: 'IS_STREAMING',
      pageText: 'normal reply',
    });
    assert.equal(
      await silentStallPresent(usableComposer),
      false,
      'a usable composer means the stream is not the detached no-input state',
    );
  }

  // Operational authorization requests must be accepted with trusted CDP
  // pointer events, preferring Always allow/Sempre permitir when present.
  {
    const calls = [];
    const cdp = {
      async evaluate(expression) {
        calls.push(['evaluate', String(expression)]);
        if (String(expression).includes('continuity-authorization-button-target')) {
          return { x: 640, y: 480, kind: 'always_allow' };
        }
        return null;
      },
      async send(method, params) {
        calls.push(['send', method, params]);
        return {};
      },
      close() {},
    };
    const result = await clickAuthorizationIfPresent(cdp);
    assert.equal(result.action, 'clicked');
    assert.equal(result.kind, 'always_allow');
    assert.ok(calls.some(call => call[0] === 'send' && call[1] === 'Input.dispatchMouseEvent' && call[2]?.type === 'mousePressed'));
  }

  {
    const cdp = {
      async evaluate(expression) {
        if (String(expression).includes('continuity-authorization-button-target')) return null;
        return null;
      },
      async send() { throw new Error('must not click without authorization'); },
      close() {},
    };
    const result = await clickAuthorizationIfPresent(cdp);
    assert.equal(result.action, 'no_request');
  }

  // A hung Runtime.evaluate in one ChatGPT tab must never freeze the 24/7
  // authorization watcher. Bound that tab, close it, and continue to the next.
  {
    let hungClosed = false;
    const tabs = [
      { type: 'page', url: 'https://chatgpt.com/c/hung-auth-tab', webSocketDebuggerUrl: 'ws://hung-auth' },
      { type: 'page', url: 'https://chatgpt.com/c/healthy-auth-tab', webSocketDebuggerUrl: 'ws://healthy-auth' },
    ];
    const healthyCalls = [];
    const started = Date.now();
    const result = await authorizationCheckOnce(
      async () => tabs,
      async tab => {
        if (tab.webSocketDebuggerUrl === 'ws://hung-auth') {
          return {
            async evaluate() { return new Promise(() => {}); },
            async send() { return {}; },
            close() { hungClosed = true; },
          };
        }
        return {
          async evaluate(expression) {
            if (String(expression).includes('continuity-authorization-button-target')) {
              return { x: 320, y: 240, kind: 'always_allow' };
            }
            return null;
          },
          async send(method, params) {
            healthyCalls.push([method, params]);
            return {};
          },
          close() {},
        };
      },
      25,
    );
    const elapsed = Date.now() - started;
    assert.equal(result.action, 'clicked');
    assert.equal(result.kind, 'always_allow');
    assert.equal(result.scanned, 2);
    assert.equal(hungClosed, true);
    assert.ok(elapsed < 500, `hung authorization tab must stay bounded, elapsed=${elapsed}ms`);
    assert.ok(healthyCalls.some(([method, params]) => method === 'Input.dispatchMouseEvent' && params?.type === 'mousePressed'));
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
      async () => { events.push('authorization'); await blocked; },
      true,
    );
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.deepEqual(events.sort(), ['authorization', 'bridge', 'reinforcement']);
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
    let confirmChecks = 0;
    const result = await reinforcementCheckOnce(
      async () => fakeCdp({ pageText: 'Transmissão interrompida. Aguardando a mensagem completa...' }),
      1,
      async () => ++confirmChecks > 1,
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
        ],
        connector: async () => fakeCdp({ pageText: 'Transmissão interrompida. Aguardando a mensagem completa...' }),
        probeBanner: async () => true,
        allowCrossDeviceDiscovery: true,
      }),
      1,
      async () => true,
      async () => ({ action: 'navigated', http_status: 200 }),
      { allowCrossDeviceDiscovery: true },
    );
    assert.equal(result.action, 'confirmed_progress');
    assert.equal(result.progress_confirmed, true);
  }

  console.log('reinforcementCheckOnce branches: PASS');
}

run().then(() => {
  console.log('CHATGPT_CONTINUITY_BRIDGE_WORKER_TEST=PASS');
}).catch(error => {
  console.error(error);
  process.exitCode = 1;
});
