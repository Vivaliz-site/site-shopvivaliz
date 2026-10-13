import assert from 'node:assert/strict';
import * as bridge from '../ops/ai-squad/codex-bridge.mjs';
assert.equal(typeof bridge.codexLoginEnvironment, 'function', 'login-only child environment must be explicit');
const source = {PATH:'/bin',CODEX_HOME:'/wrong',OPENAI_API_KEY:'test-only',CODEX_API_KEY:'test-only',CODEX_ACCESS_TOKEN:'test-only',OPENAI_BASE_URL:'https://example.com',OPENAI_FEDERATION_RULE_ID:'test-only',OPENAI_IDENTITY_TOKEN_FILE:'/test-only'};
const actual = bridge.codexLoginEnvironment(source, '/corporate-dev');
assert.deepEqual(actual,{PATH:'/bin',CODEX_HOME:'/corporate-dev'});
assert.equal(source.OPENAI_API_KEY,'test-only','caller environment is not mutated');
assert.deepEqual(bridge.CHATGPT_ONLY_OVERRIDES,['-c','forced_login_method="chatgpt"','-c','model_provider="openai"']);
console.log('OKX_CODEX_LOGIN_ONLY_TEST=PASS');
