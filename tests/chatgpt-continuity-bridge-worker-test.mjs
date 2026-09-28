import assert from 'node:assert/strict';
import {
  conversationIsGenerating,
  composerIsUsable,
  errorBannerPresent,
  latestConversationMeta,
  alignToLatestConversation,
  attemptNudge,
  reinforcementCheckOnce,
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
  // conversationIsGenerating / composerIsUsable / errorBannerPresent are
  // thin wrappers -- confirm they read the right signal.
  assert.equal(await conversationIsGenerating(fakeCdp({ generating: true })), true);
  assert.equal(await conversationIsGenerating(fakeCdp({ generating: false })), false);
  assert.equal(await composerIsUsable(fakeCdp({ composerUsable: false })), false);
  assert.equal(await errorBannerPresent(fakeCdp({ pageText: 'Something went wrong. Please try again.' })), true);
  assert.equal(await errorBannerPresent(fakeCdp({ pageText: 'Here is your normal completed answer.' })), false);
  // Confirmed live on ChatGPT Free (mobile app), 2026-09-27 -- the actual
  // observed banner text, not a guess.
  assert.equal(
    await errorBannerPresent(fakeCdp({ pageText: 'Transmissão interrompida. Aguardando a mensagem completa...' })),
    true,
  );
  assert.equal(await errorBannerPresent(fakeCdp({ pageText: 'Streaming interrupted. Waiting for the complete message...' })), true);

  console.log('conversationIsGenerating/composerIsUsable/errorBannerPresent: PASS');

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

  console.log('cross-device latest-conversation alignment: PASS');

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
  // That state is not a real in-flight stream and must be repaired before
  // sending the single continuation message.
  const staleComplete = await attemptNudge('task-stale-complete', async () => fakeCdp({
    generating: true,
    streamStatus: 'COMPLETE',
    staleStopClearSucceeds: true,
    sendSucceeds: true,
  }), async () => true);
  assert.equal(staleComplete.result_status, 'PROGRESS_CONFIRMED', 'stale COMPLETE recovery is success only after assistant progress');
  assert.match(staleComplete.detail, /stale COMPLETE/i);

  const noComposer = await attemptNudge('task-1', async () => fakeCdp({ composerUsable: false }));
  assert.equal(noComposer.result_status, 'CONVERSATION_NOT_FOUND');

  const sentOk = await attemptNudge('task-1', async () => fakeCdp({ sendSucceeds: true }), async () => true);
  assert.equal(sentOk.result_status, 'PROGRESS_CONFIRMED');

  const sentButNoProgress = await attemptNudge('task-no-progress', async () => fakeCdp({ sendSucceeds: true }), async () => false);
  assert.equal(sentButNoProgress.result_status, 'SENT_UNCONFIRMED');
  assert.match(sentButNoProgress.detail, /no assistant progress/i);

  const sendFailed = await attemptNudge('task-1', async () => fakeCdp({ sendSucceeds: false }), async () => true);
  assert.equal(sendFailed.result_status, 'ERROR');

  const connectFailed = await attemptNudge('task-1', async () => { throw new Error('CDP endpoint unreachable'); });
  assert.equal(connectFailed.result_status, 'ERROR');
  assert.ok(connectFailed.detail.includes('unreachable'), 'connect failures must surface their reason in detail');

  console.log('attemptNudge branches: PASS');

  // The reinforcement path must align to the latest cross-device thread
  // before inspecting the banner.
  {
    const events = [];
    const cdp = fakeCdp({ pageText: 'normal reply' });
    const result = await reinforcementCheckOnce(
      async () => { events.push('connect'); return cdp; },
      1,
      async () => true,
      async () => { events.push('align'); return { action: 'already_latest' }; },
    );
    assert.equal(result.action, 'no_banner');
    assert.deepEqual(events.slice(0, 2), ['connect', 'align']);
  }

  // reinforcementCheckOnce: no banner at all -> no-op, no second connect.
  {
    let connectCalls = 0;
    const result = await reinforcementCheckOnce(
      async () => { connectCalls += 1; return fakeCdp({ pageText: 'normal reply' }); },
      1,
      async () => true,
      async () => ({ action: 'already_latest' }),
    );
    assert.equal(result.action, 'no_banner');
    assert.equal(connectCalls, 1, 'a clean page must not trigger the confirm re-check');
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

  console.log('reinforcementCheckOnce branches: PASS');
}

run().then(() => {
  console.log('CHATGPT_CONTINUITY_BRIDGE_WORKER_TEST=PASS');
}).catch(error => {
  console.error(error);
  process.exitCode = 1;
});
