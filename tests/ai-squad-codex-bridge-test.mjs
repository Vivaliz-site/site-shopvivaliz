import assert from 'node:assert/strict';
import {
  validateRequest,
  classifyRateLimit,
  exactModelMatches,
  sanitizeBridgeError,
  remainingRequestMs,
} from '../ops/ai-squad/codex-bridge.mjs';

const valid = validateRequest({
  model: 'gpt-5.6-sol',
  effort: 'xhigh',
  prompt: 'pesquise',
  web_search: true,
});
assert.equal(valid.model, 'gpt-5.6-sol');
assert.equal(valid.effort, 'xhigh');
assert.equal(valid.web_search, true);

assert.throws(() => validateRequest({
  model: 'gpt-4o',
  effort: 'xhigh',
  prompt: 'x',
  web_search: true,
}), /invalid_model/);

assert.throws(() => validateRequest({
  model: 'gpt-5.6-sol',
  effort: 'extreme',
  prompt: 'x',
  web_search: true,
}), /invalid_effort/);

assert.equal(classifyRateLimit({
  rateLimitReachedType: 'usage_limit',
}), 'exhausted');

assert.equal(classifyRateLimit({
  primary: { usedPercent: 1 },
  rateLimitReachedType: null,
}), 'available');

assert.equal(exactModelMatches('gpt-5.6-sol', 'gpt-5.6-sol'), true);
assert.equal(exactModelMatches('gpt-5.6-sol', 'gpt-5.6-terra'), false);

assert.equal(remainingRequestMs(5000, 1000, 10000), 4000);
assert.equal(remainingRequestMs(5000, 1000, 2500), 2500);
assert.throws(() => remainingRequestMs(1000, 1000, 5000), /request_timeout/);

const safe = sanitizeBridgeError(
  'Authorization: Bearer sk-secret-token quota reached for user@example.com'
);
assert(!safe.includes('sk-secret-token'));
assert(!safe.includes('user@example.com'));

console.log('AI_SQUAD_CODEX_BRIDGE_TEST=PASS');
