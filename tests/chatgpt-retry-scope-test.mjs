import test from 'node:test';
import assert from 'node:assert/strict';
import { clickRecoverableRetryButton } from '../scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs';

test('conversation Retry is selected even when a sidebar history Retry is also visible', async () => {
  const calls = [];
  const cdp = {
    async evaluate(source) {
      const text = String(source);
      if (text.includes('/stream_status')) return { http_status: 200, status: 'COMPLETE' };
      if (text.includes('continuity-retry-button-target')) {
        // Reproduce the live DOM: the document has two visible Retry buttons,
        // but only one lives in the conversation <main> surface. The old
        // document-wide selector sees ambiguity and returns no target.
        if (!text.includes("document.querySelector('main')")) return null;
        return { x: 120, y: 140 };
      }
      return true;
    },
    async send(method, params = {}) {
      calls.push({ method, type: params.type || '' });
      return {};
    },
  };

  const clicked = await clickRecoverableRetryButton(cdp);
  assert.equal(clicked, true, 'sidebar Retry must not make the conversation Retry ambiguous');
  assert.equal(
    calls.filter(call => call.method === 'Input.dispatchMouseEvent' && call.type === 'mouseReleased').length,
    1,
    'exactly one trusted click must be dispatched to the conversation Retry',
  );
});
