import assert from 'node:assert/strict';
import {
  conversationIsGenerating,
  composerIsUsable,
  errorBannerPresent,
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
  return {
    calls,
    async evaluate(expression) {
      calls.push(expression);
      if (expression.includes('/stream_status')) return { http_status: 200, status: streamStatus };
      if (expression.includes('stale-complete-stop-clear')) return staleStopClearSucceeds;
      if (expression.includes('stop-button')) return generating;
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

  // attemptNudge branches, driven entirely through the injected connect().
  const generating = await attemptNudge('task-1', async () => fakeCdp({ generating: true }));
  assert.equal(generating.result_status, 'STALLED_NOT_CONFIRMED', 'must never nudge mid-stream');

  // Live failure reproduced 2026-09-28: the DOM can keep a stale Stop button
  // even when the conversation backend already reports stream_status=COMPLETE.
  // That state is not a real in-flight stream and must be repaired before
  // sending the single continuation message.
  const staleComplete = await attemptNudge('task-stale-complete', async () => fakeCdp({
    generating: true,
    streamStatus: 'COMPLETE',
    staleStopClearSucceeds: true,
    sendSucceeds: true,
  }));
  assert.equal(staleComplete.result_status, 'SENT', 'stale COMPLETE stream must be recoverable');
  assert.match(staleComplete.detail, /stale COMPLETE/i);

  const noComposer = await attemptNudge('task-1', async () => fakeCdp({ composerUsable: false }));
  assert.equal(noComposer.result_status, 'CONVERSATION_NOT_FOUND');

  const sentOk = await attemptNudge('task-1', async () => fakeCdp({ sendSucceeds: true }));
  assert.equal(sentOk.result_status, 'SENT');

  const sendFailed = await attemptNudge('task-1', async () => fakeCdp({ sendSucceeds: false }));
  assert.equal(sendFailed.result_status, 'ERROR');

  const connectFailed = await attemptNudge('task-1', async () => { throw new Error('CDP endpoint unreachable'); });
  assert.equal(connectFailed.result_status, 'ERROR');
  assert.ok(connectFailed.detail.includes('unreachable'), 'connect failures must surface their reason in detail');

  console.log('attemptNudge branches: PASS');

  // reinforcementCheckOnce: no banner at all -> no-op, no second connect.
  {
    let connectCalls = 0;
    const result = await reinforcementCheckOnce(async () => { connectCalls += 1; return fakeCdp({ pageText: 'normal reply' }); }, 1);
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
    }, 1);
    assert.equal(result.action, 'self_resolved');
    assert.equal(connectCalls, 2, 'must re-check exactly once after the confirm delay');
  }

  // Banner still present on the confirm re-check -> must send.
  {
    const result = await reinforcementCheckOnce(async () => fakeCdp({ pageText: 'Transmissão interrompida. Aguardando a mensagem completa...' }), 1);
    assert.equal(result.action, 'confirmed');
    assert.equal(result.sent, true);
  }

  console.log('reinforcementCheckOnce branches: PASS');
}

run().then(() => {
  console.log('CHATGPT_CONTINUITY_BRIDGE_WORKER_TEST=PASS');
}).catch(error => {
  console.error(error);
  process.exitCode = 1;
});
