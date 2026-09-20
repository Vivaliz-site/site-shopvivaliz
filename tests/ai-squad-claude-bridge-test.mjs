import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import {
  validateRequest,
  sanitizeBridgeError,
  classifyClaudeError,
  buildClaudeArgs,
  isDirectInvocation,
} from '../ops/ai-squad/claude-bridge.mjs';

const valid = validateRequest({
  model: 'claude-opus-5',
  effort: 'xhigh',
  system: 'system-safe',
  prompt: 'secret-user-prompt',
  web_search: true,
});
assert.equal(valid.model, 'claude-opus-5');
assert.equal(valid.effort, 'xhigh');
assert.equal(valid.web_search, true);

assert.throws(() => validateRequest({
  model: 'claude-fable-5',
  effort: 'xhigh',
  system: 'x',
  prompt: 'x',
}), /invalid_model/);

assert.throws(() => validateRequest({
  model: 'claude-opus-5',
  effort: 'extreme',
  system: 'x',
  prompt: 'x',
}), /invalid_effort/);

const args = buildClaudeArgs(valid);
assert(args.includes('--safe-mode'));
assert(args.includes('--restricted'));
assert(args.includes('--no-session-persistence'));
assert(args.includes('WebSearch,WebFetch'));
assert(!args.includes(valid.prompt), 'user prompt must go over stdin, not argv');

const noWebArgs = buildClaudeArgs({ ...valid, web_search: false });
assert.equal(noWebArgs[noWebArgs.indexOf('--tools') + 1], '');

assert.equal(classifyClaudeError('OAuth session expired'), 'auth');
assert.equal(classifyClaudeError('credit balance is too low'), 'quota');
assert.equal(classifyClaudeError('request_timeout'), 'timeout');

const safe = sanitizeBridgeError('Authorization: Bearer sk-ant-oat-secret user@example.com');
assert(!safe.includes('sk-ant-oat-secret'));
assert(!safe.includes('user@example.com'));

const invocationDir = fs.mkdtempSync(path.join(os.tmpdir(), 'ai-squad-claude-invocation-'));
const bridgeTarget = fileURLToPath(new URL('../ops/ai-squad/claude-bridge.mjs', import.meta.url));
const bridgeLink = path.join(invocationDir, 'current-claude-bridge.mjs');
fs.symlinkSync(bridgeTarget, bridgeLink);
assert.equal(isDirectInvocation(pathToFileURL(bridgeTarget).href, bridgeLink), true);
assert.equal(isDirectInvocation(pathToFileURL(bridgeTarget).href, import.meta.filename), false);
fs.rmSync(invocationDir, { recursive: true, force: true });

console.log('AI_SQUAD_CLAUDE_BRIDGE_TEST=PASS');
