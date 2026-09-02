import { readFile } from 'node:fs/promises';
const patcher = await readFile(new URL('../scripts/patch-desktop-commander-session-persistence.mjs', import.meta.url), 'utf8');
for (const needle of [
  'PROACTIVE_REFRESH_MARGIN_MS',
  'PROACTIVE_REFRESH_CHECK_MS',
  'expires_at',
  'refreshSession()',
  'PROACTIVE_SESSION_REFRESH_OK',
  'PROACTIVE_SESSION_REFRESH_FAILED',
]) {
  if (!patcher.includes(needle)) {
    throw new Error(`patcher missing proactive refresh guard: ${needle}`);
  }
}
if (!patcher.includes('10 * 60 * 1000')) {
  throw new Error('proactive refresh margin must be at least ten minutes');
}
console.log('desktop-commander-proactive-refresh-contract: ok');
