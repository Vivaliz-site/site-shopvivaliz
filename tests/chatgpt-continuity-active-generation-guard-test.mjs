import assert from 'node:assert/strict';
import { attemptNudge } from '../scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs';

const calls = [];
let generating = true;
const cdp = {
  async evaluate(expression) {
    calls.push(String(expression));
    if (String(expression).includes('/stream_status')) return { http_status: 200, status: 'IN_PROGRESS' };
    if (String(expression).includes('continuity-error-banner-probe')) return false;
    if (String(expression).includes('continuity-additional-checks-probe')) return false;
    if (String(expression).includes('continuity-transmission-error-probe')) return false;
    if (String(expression).includes('continuity-request-timeout-probe')) return false;
    if (String(expression).includes('stop-button')) return generating;
    if (String(expression).includes('location.pathname')) return '/c/11111111-2222-3333-4444-555555555555';
    if (String(expression).includes('location.reload')) return true;
    return null;
  },
  async pageState() { return { href: 'https://chatgpt.com/c/11111111-2222-3333-4444-555555555555', title: 'ChatGPT', text: '' }; },
  close() {},
};

const result = await attemptNudge('active-generation', async () => cdp, async () => false);
assert.equal(result.result_status, 'STALLED_NOT_CONFIRMED');
assert.equal(
  calls.some(call => call.includes('location.reload')),
  false,
  'a healthy IN_PROGRESS generation must never be reloaded merely because a checkpoint is stale',
);
assert.equal(
  calls.some(call => call.includes('b.click()')),
  false,
  'a healthy IN_PROGRESS generation must never receive a duplicate continuation',
);
assert.match(result.detail, /active.*generation|in.progress/i);
console.log('active generation guard: PASS');
