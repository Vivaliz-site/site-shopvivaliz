import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const taskStateDir = fs.mkdtempSync(path.join(os.tmpdir(), 'fred-orphaned-bound-stream-'));
process.env.SHOPVIVALIZ_AGENT_TASK_STATE_DIR = taskStateDir;

const conversationId = 'fred-bound-orphaned-stream';
const taskId = 'fred-orphaned-bound-stream-regression';
fs.writeFileSync(path.join(taskStateDir, taskId + '.json'), JSON.stringify({
  schema_version: 1,
  task_id: taskId,
  status: 'RUNNING',
  repository: 'Vivaliz-site/site-shopvivaliz',
  browser_session: 'fred',
  conversation_id: conversationId,
}));

const { attemptNudge } = await import('../scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs');

let reloaded = false;
let actuatorUsed = false;
const cdp = {
  close() {},
  async send() {
    actuatorUsed = true;
    throw new Error('orphaned stream proof must not use trusted input');
  },
  async evaluate(source) {
    const text = String(source);
    if (text.includes('continuity-browser-account-match')) return true;
    if (text.includes('/stream_status')) {
      return { http_status: 200, status: 'IS_STREAMING' };
    }
    if (text.includes('conversation-turn-state')) {
      return {
        http_status: 200,
        node_id: 'assistant-orphaned',
        role: 'assistant',
        end_turn: false,
        child_count: 0,
        content_text_length: 0,
        message_status: 'finished_successfully',
      };
    }
    if (text.includes('continuity-conversation-unavailable-probe')) return false;
    if (text === 'location.pathname') return '/c/' + conversationId;
    if (text.includes('stop-button')) return false;
    if (
      text.includes('prompt-textarea')
      && text.includes('[role="textbox"][contenteditable="true"]')
      && !text.includes('insertText')
      && !text.includes('proto.value')
      && !text.includes('b.click()')
    ) return true;
    if (
      text.includes('continuity-error-banner-probe')
      || text.includes('continuity-additional-checks-probe')
      || text.includes('continuity-stopped-thinking-probe')
      || text.includes('continuity-streaming-interrupted-probe')
      || text.includes('continuity-request-timeout-probe')
    ) return false;
    if (text.includes('data-message-author-role="assistant"')) {
      return {
        count: 1,
        lastText: 'prior answer',
        lastLength: 12,
        lastKey: 'assistant-prior',
        surfaceText: 'prior answer',
        surfaceLength: 12,
        conversationPath: '/c/' + conversationId,
        snapshotSource: 'legacy',
      };
    }
    if (text.includes('location.reload')) {
      reloaded = true;
      return true;
    }
    return false;
  },
};

try {
  const result = await attemptNudge(
    taskId,
    async () => cdp,
    async () => false,
    async () => { throw new Error('composer/send path must remain unreachable'); },
    conversationId,
    async () => false,
  );

  assert.equal(result.result_status, 'STALLED_NOT_CONFIRMED');
  assert.equal(result.sent, false);
  assert.equal(
    reloaded,
    true,
    'the full contradictory orphaned-stream signature must receive one passive reattach before deferral',
  );
  assert.match(
    result.detail,
    /passive reattach|active stream remains unconfirmed/i,
    'post-reattach failure must remain fail-closed rather than sending a continuation',
  );
  assert.equal(actuatorUsed, false, 'orphaned-stream classification alone must never authorize trusted input');
  console.log('FRED_ORPHANED_BOUND_STREAM_TEST=PASS');
} finally {
  fs.rmSync(taskStateDir, { recursive: true, force: true });
}
