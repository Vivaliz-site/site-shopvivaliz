import assert from 'node:assert/strict';
import {
  conversationIsGenerating,
  composerIsUsable,
  errorBannerPresent,
  attemptNudge,
} from '../scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs';

// Fake CDP objects let the decision logic (when to nudge, what result to
// report) be tested without a real browser or WebSocket -- exactly the
// branches attemptNudge() takes, driven purely by what evaluate()/pageState()
// return.
function fakeCdp({ generating = false, composerUsable = true, pageText = '', sendSucceeds = true } = {}) {
  const calls = [];
  return {
    calls,
    async evaluate(expression) {
      calls.push(expression);
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

  console.log('conversationIsGenerating/composerIsUsable/errorBannerPresent: PASS');

  // attemptNudge branches, driven entirely through the injected connect().
  const generating = await attemptNudge('task-1', async () => fakeCdp({ generating: true }));
  assert.equal(generating.result_status, 'STALLED_NOT_CONFIRMED', 'must never nudge mid-stream');

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
}

run().then(() => {
  console.log('CHATGPT_CONTINUITY_BRIDGE_WORKER_TEST=PASS');
}).catch(error => {
  console.error(error);
  process.exitCode = 1;
});
