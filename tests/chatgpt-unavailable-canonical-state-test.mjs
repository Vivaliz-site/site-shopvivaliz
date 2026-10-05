import test from 'node:test';
import assert from 'node:assert/strict';

const { attemptNudge } = await import('../scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs');

function canonicalUnavailableCdp({
  turnStatus = 200,
  streamStatus = 'IS_STREAMING',
  endTurn = false,
} = {}) {
  const calls = [];
  return {
    calls,
    async evaluate(expression) {
      const source = String(expression);
      calls.push(source);
      if (source === 'location.pathname') return '/c/live-bound-conversation';
      if (source.includes('continuity-conversation-unavailable-probe')) return true;
      if (source.includes('/stream_status')) {
        return { http_status: turnStatus === 200 ? 200 : 0, status: streamStatus };
      }
      if (source.includes('conversation-turn-state')) {
        return {
          http_status: turnStatus,
          node_id: turnStatus === 200 ? 'assistant-node-live' : '',
          role: turnStatus === 200 ? 'assistant' : '',
          end_turn: turnStatus === 200 ? endTurn : null,
          child_count: 0,
          content_text_length: 0,
          message_status: turnStatus === 200 ? 'finished_successfully' : 'HTTP_ERROR',
        };
      }
      if (source.includes('continuity-error-banner-probe')) return false;
      if (source.includes('continuity-additional-checks-probe')) return false;
      if (source.includes('continuity-stopped-thinking-probe')) return false;
      if (source.includes('continuity-streaming-interrupted-probe')) return false;
      if (source.includes('continuity-request-timeout-probe')) return false;
      if (source.includes('stop-button')) return false;
      if (source.includes('location.reload')) return true;
      if (source.includes('const candidates=[')) {
        return {
          count: 1,
          lastText: 'previous assistant reply',
          lastLength: 24,
          lastKey: 'assistant-old',
          surfaceText: 'Could not load this ChatGPT conversation',
          surfaceLength: 39,
          conversationPath: '/c/live-bound-conversation',
          snapshotSource: 'legacy',
        };
      }
      return false;
    },
    async send() {
      throw new Error('trusted input must not be used during hydration recovery');
    },
    close() {},
  };
}

test('canonical active unfinished conversation overrides unavailable UI and uses passive recovery', async () => {
  const cdp = canonicalUnavailableCdp();
  let confirmCalls = 0;

  const outcome = await attemptNudge(
    'task-canonical-unavailable-active',
    async () => cdp,
    async () => {
      confirmCalls += 1;
      return true;
    },
    async () => {
      throw new Error('composer path must not be reached when passive recovery succeeds');
    },
    'live-bound-conversation',
  );

  assert.equal(outcome.result_status, 'PROGRESS_CONFIRMED');
  assert.equal(outcome.sent, false);
  assert.equal(outcome.conversation_id, 'live-bound-conversation');
  assert.equal(confirmCalls, 1);
  assert.ok(cdp.calls.some(source => source.includes('/stream_status')));
  assert.ok(cdp.calls.some(source => source.includes('conversation-turn-state')));
  assert.ok(cdp.calls.some(source => source.includes('location.reload')));
});

test('canonical 404 still permits CONVERSATION_NOT_FOUND for unavailable UI', async () => {
  const cdp = canonicalUnavailableCdp({ turnStatus: 404, streamStatus: 'NO_CONVERSATION', endTurn: null });

  const outcome = await attemptNudge(
    'task-canonical-unavailable-missing',
    async () => cdp,
    async () => false,
    async () => false,
    'live-bound-conversation',
  );

  assert.equal(outcome.result_status, 'CONVERSATION_NOT_FOUND');
  assert.equal(outcome.sent, false);
});
