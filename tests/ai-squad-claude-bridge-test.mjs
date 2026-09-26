import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import {
  validateRequest,
  sanitizeBridgeError,
  classifyClaudeError,
  claudeFailureDetail,
  buildClaudeArgs,
  isDirectInvocation,
  resolveClaudeAuthSource,
  parseClaudeOutput,
  buildSourceRetryPrompt,
  answerClaudeRequest,
  shouldReprobeAuth,
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
assert(args.includes('stream-json'));
assert(args.includes('--verbose'));
assert.equal(args[args.indexOf('--allowedTools') + 1], 'WebSearch,WebFetch', 'web search tools must be explicitly pre-authorized under dontAsk');
assert(!args.includes(valid.prompt), 'user prompt must go over stdin, not argv');

const noWebArgs = buildClaudeArgs({ ...valid, web_search: false });
assert.equal(noWebArgs[noWebArgs.indexOf('--tools') + 1], '');
assert.equal(noWebArgs.includes('--allowedTools'), false, 'non-web requests must not pre-authorize web tools');

assert.equal(classifyClaudeError('OAuth session expired'), 'auth');
assert.equal(classifyClaudeError('Failed to refresh OAuth token: another Claude Code process is refreshing it or exited mid-refresh'), 'oauth_refresh_contention');
assert.equal(classifyClaudeError('credit balance is too low'), 'quota');
assert.equal(classifyClaudeError("You've hit your session limit · resets 12:30am (UTC)"), 'quota');
assert.equal(classifyClaudeError('request_timeout'), 'timeout');
assert.equal(classifyClaudeError('source_missing'), 'source_missing');
assert.equal(shouldReprobeAuth({ authenticated: true, checked_at: 0 }, 10000, 5000), false);
assert.equal(shouldReprobeAuth({ authenticated: false, checked_at: 1000 }, 7001, 5000), true);
assert.equal(shouldReprobeAuth({ authenticated: false, checked_at: 4000 }, 7001, 5000), false);

const sessionLimitDetail = claudeFailureDetail({
  code: 1,
  stderr: '',
  stdout: JSON.stringify({
    terminal_reason: 'api_error',
    api_error_status: 429,
    result: "You've hit your session limit · resets 12:30am (UTC)",
  }),
});
assert.match(sessionLimitDetail, /session limit/);

const parsedStream = parseClaudeOutput([
  JSON.stringify({ type: 'assistant', message: { content: [{ type: 'tool_use', name: 'WebSearch', input: { query: 'example' } }] } }),
  JSON.stringify({ type: 'user', message: { content: [{ type: 'tool_result', content: [{ type: 'text', text: 'Source https://example.com/product?a=1' }] }] } }),
  JSON.stringify({ type: 'result', subtype: 'success', is_error: false, result: 'Resumo final sem URL literal.', usage: { input_tokens: 10 } }),
].join('\n'));
assert.equal(parsedStream.result, 'Resumo final sem URL literal.');
assert.equal(parsedStream.is_error, false);
assert.equal(parsedStream.usage.input_tokens, 10);
assert.deepEqual(parsedStream.sources, ['https://example.com/product?a=1']);

const parsedLegacy = parseClaudeOutput(JSON.stringify({
  is_error: false,
  result: 'Veja https://example.org/legacy',
  usage: { output_tokens: 4 },
}));
assert.deepEqual(parsedLegacy.sources, ['https://example.org/legacy']);

assert.match(buildSourceRetryPrompt('pesquise'), /WebSearch/);
assert.match(buildSourceRetryPrompt('pesquise'), /https?:\/\//);
let budgetNow = 1000;
const budgetTimeouts = [];
const budgetedRetry = await answerClaudeRequest(valid, {
  auth: { configured: true, token: '' },
  timeoutMs: 10000,
  now: () => budgetNow,
  run: async (callArgs, prompt, timeoutMs) => {
    budgetTimeouts.push(timeoutMs);
    if (budgetTimeouts.length === 1) {
      budgetNow = 8000;
      return { code: 0, stdout: JSON.stringify({ type: 'result', is_error: false, result: 'Resposta sem URL.' }), stderr: '' };
    }
    return { code: 0, stdout: JSON.stringify({ type: 'result', is_error: false, result: 'Fonte: https://example.com/budget' }), stderr: '' };
  },
});
assert.deepEqual(budgetTimeouts, [10000, 3000], 'source retry must consume the original total request deadline');
assert.equal(budgetedRetry.ok, true);
const sourceRetryCalls = [];
const sourceRetryResult = await answerClaudeRequest(valid, {
  auth: { configured: true, token: '' },
  run: async (callArgs, prompt) => {
    sourceRetryCalls.push({ callArgs, prompt });
    const result = sourceRetryCalls.length === 1 ? 'Resposta sem URL verificável.' : 'Fonte verificada: https://example.com/fonte';
    return { code: 0, stdout: JSON.stringify({ type: 'result', is_error: false, result }), stderr: '' };
  },
});
assert.equal(sourceRetryCalls.length, 2, 'source-less web response must be retried once');
assert(sourceRetryCalls.every(({ callArgs }) => callArgs.includes('WebSearch,WebFetch')));
assert.deepEqual(sourceRetryResult.sources, ['https://example.com/fonte']);

let sourceMissingCalls = 0;
await assert.rejects(() => answerClaudeRequest(valid, {
  auth: { configured: true, token: '' },
  run: async () => {
    sourceMissingCalls += 1;
    return { code: 0, stdout: JSON.stringify({ type: 'result', is_error: false, result: 'Ainda sem URL.' }), stderr: '' };
  },
}), /source_missing/);
assert.equal(sourceMissingCalls, 2, 'source-less web response must fail after the single retry');

const nonWebRequest = { ...valid, web_search: false };
let queuedActive = 0;
let queuedMaxActive = 0;
const queuedRun = async () => {
  queuedActive += 1;
  queuedMaxActive = Math.max(queuedMaxActive, queuedActive);
  await new Promise(resolve => setTimeout(resolve, 15));
  queuedActive -= 1;
  return { code: 0, stdout: JSON.stringify({ type: 'result', is_error: false, result: 'Resposta serializada.' }), stderr: '' };
};
await Promise.all([
  answerClaudeRequest(nonWebRequest, { auth: { configured: true, token: '' }, run: queuedRun }),
  answerClaudeRequest(nonWebRequest, { auth: { configured: true, token: '' }, run: queuedRun }),
]);
assert.equal(queuedMaxActive, 1, 'Claude work must serialize concurrent requests to protect OAuth refresh');

let refreshRetryCalls = 0;
const refreshBackoffs = [];
const refreshRetryResult = await answerClaudeRequest(nonWebRequest, {
  auth: { configured: true, token: '' },
  sleep: async delay => { refreshBackoffs.push(delay); },
  run: async () => {
    refreshRetryCalls += 1;
    if (refreshRetryCalls === 1) {
      return { code: 1, stdout: '', stderr: 'Failed to refresh OAuth token: another Claude Code process is refreshing it or exited mid-refresh' };
    }
    return { code: 0, stdout: JSON.stringify({ type: 'result', is_error: false, result: 'Resposta após refresh serializado.' }), stderr: '' };
  },
});
assert.equal(refreshRetryCalls, 2, 'OAuth refresh contention must have exactly one bounded retry');
assert.deepEqual(refreshBackoffs, [250], 'OAuth refresh contention retry must use bounded backoff');
assert.equal(refreshRetryResult.ok, true);

let persistentRefreshCalls = 0;
await assert.rejects(() => answerClaudeRequest(nonWebRequest, {
  auth: { configured: true, token: '' },
  sleep: async () => {},
  run: async () => {
    persistentRefreshCalls += 1;
    return { code: 1, stdout: '', stderr: 'Failed to refresh OAuth token: another Claude Code process is refreshing it or exited mid-refresh' };
  },
}), /refresh OAuth token/);
assert.equal(persistentRefreshCalls, 2, 'persistent OAuth refresh contention must fail after one bounded retry');

const safe = sanitizeBridgeError('Authorization: Bearer sk-ant-oat-secret user@example.com');
assert(!safe.includes('sk-ant-oat-secret'));
assert(!safe.includes('user@example.com'));

const authDir = fs.mkdtempSync(path.join(os.tmpdir(), 'ai-squad-claude-auth-'));
const credentialPath = path.join(authDir, '.credentials.json');
fs.writeFileSync(credentialPath, JSON.stringify({
  claudeAiOauth: {
    accessToken: 'credential-access-token',
    refreshToken: 'credential-refresh-token',
  },
}), { mode: 0o600 });
const explicitAuth = resolveClaudeAuthSource('explicit-token', credentialPath);
assert.equal(explicitAuth.mode, 'env_token');
assert.equal(explicitAuth.configured, true);
assert.equal(explicitAuth.token, 'explicit-token');
const storedAuth = resolveClaudeAuthSource('', credentialPath);
assert.equal(storedAuth.mode, 'credential_store');
assert.equal(storedAuth.configured, true);
assert.equal(storedAuth.token, '', 'credential-store mode must let Claude CLI own refresh instead of exporting a stale access token');
assert.deepEqual(resolveClaudeAuthSource('', path.join(authDir, 'missing.json')), {
  mode: 'none',
  configured: false,
  token: '',
});
fs.rmSync(authDir, { recursive: true, force: true });

const invocationDir = fs.mkdtempSync(path.join(os.tmpdir(), 'ai-squad-claude-invocation-'));
const bridgeTarget = fileURLToPath(new URL('../ops/ai-squad/claude-bridge.mjs', import.meta.url));
const bridgeLink = path.join(invocationDir, 'current-claude-bridge.mjs');
fs.symlinkSync(bridgeTarget, bridgeLink);
assert.equal(isDirectInvocation(pathToFileURL(bridgeTarget).href, bridgeLink), true);
assert.equal(isDirectInvocation(pathToFileURL(bridgeTarget).href, import.meta.filename), false);
fs.rmSync(invocationDir, { recursive: true, force: true });

const bridgeSource = fs.readFileSync(bridgeTarget, 'utf8');
assert.match(bridgeSource, /auth status.*cannot perform inference/s, 'health probe must reject auth-status-only false green');
assert.match(bridgeSource, /buildClaudeArgs\(request\).*20000/s, 'health probe must execute a bounded real inference');
assert.match(bridgeSource, /credential_store_configured/, 'health must expose credential-store auth source without secrets');
assert.match(bridgeSource, /shouldReprobeAuth\(authState\).*scheduleAuthProbe/s, 'false or stale health must schedule a fresh auth probe');
console.log('AI_SQUAD_CLAUDE_BRIDGE_TEST=PASS');
