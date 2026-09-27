import fs from 'node:fs';
import path from 'node:path';

const OUTPUT = process.env.CHATGPT_ACCOUNT_DIAG_OUTPUT || '/tmp/chatgpt-account-diagnostic.json';
const OUTPUT_DIR = process.env.CHATGPT_ACCOUNT_DIAG_DIR || path.dirname(OUTPUT);
fs.mkdirSync(OUTPUT_DIR, { recursive: true, mode: 0o700 });

const result = {
  schema: 2,
  started_at: new Date().toISOString(),
  mode: 'passive_only',
  automated_prompt_submission: false,
  blocker: 'chatgpt_web_automation_risk_guard_active',
  support_case: '15426555',
  ok: false,
  finished_at: new Date().toISOString(),
};

fs.writeFileSync(OUTPUT, JSON.stringify(result, null, 2) + '\n', { mode: 0o600 });
console.log('CHATGPT_ACCOUNT_DIAGNOSTIC=' + JSON.stringify(result));
console.error('Automated ChatGPT Web prompt submission is disabled while the account/workspace restriction hypothesis remains unresolved.');
process.exitCode = 2;
