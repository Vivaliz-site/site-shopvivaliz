import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { confirmAssistantProgress } from '../scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs';

test('active streaming uses lightweight stream status and performs only one final full conversation read', async () => {
  let streamPolls = 0;
  let fullConversationReads = 0;
  const fingerprint = createHash('sha256').update('/c/read-rate-thread').digest('hex');
  const baselineSnapshot = {
    count: 0,
    lastText: '',
    lastLength: 0,
    lastKey: '',
    surfaceText: 'ChatGPT is responding',
    surfaceLength: 21,
    snapshotSource: 'main',
    conversationFingerprint: fingerprint,
  };
  const turnBaseline = {
    http_status: 200,
    node_id: 'user-before-send',
    role: 'user',
    end_turn: true,
    child_count: 0,
    content_text_length: 8,
    message_status: 'finished_successfully',
  };

  const cdp = {
    async evaluate(expression) {
      const source = String(expression);
      if (source.includes('snapshotSource')) return { ...baselineSnapshot, conversationPath: '/c/read-rate-thread' };
      if (source.includes('continuity-transmission-error-probe')) return false;
      if (source.includes("document.querySelector('[data-testid=\"stop-button\"]')")) return false;
      if (source.includes('/stream_status')) {
        streamPolls += 1;
        return { http_status: 200, status: streamPolls >= 3 ? 'COMPLETE' : 'IS_STREAMING' };
      }
      if (source.includes('conversation-turn-state')) {
        fullConversationReads += 1;
        if (streamPolls >= 3) {
          return {
            http_status: 200,
            node_id: 'assistant-after-send',
            role: 'assistant',
            end_turn: true,
            child_count: 0,
            content_text_length: 32,
            message_status: 'finished_successfully',
          };
        }
        return { ...turnBaseline };
      }
      return false;
    },
  };

  const confirmed = await confirmAssistantProgress(cdp, baselineSnapshot, 7000, 1000, turnBaseline);
  assert.equal(confirmed, true, 'the completed real assistant response must still certify progress');
  assert.equal(fullConversationReads, 1, 'full conversation history must be read only once after stream completion');
  assert.ok(streamPolls >= 3, 'lightweight stream status should carry the active-stream polling');
});
