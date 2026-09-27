import fs from 'node:fs';
import { spawn } from 'node:child_process';

const ROOT = process.env.GITHUB_WORKSPACE || process.cwd();
const refreshScript = `${ROOT}/scripts/refresh-backend-delete-repo-scope.sh`;
const browserScript = `${ROOT}/scripts/github-device-auth-delete-repo.mjs`;
const logFile = `/tmp/shopvivaliz-gh-refresh-${process.pid}.log`;
const maxWaitMs = 45_000;

function sleep(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }
function scopePresent() {
  return new Promise(resolve => {
    const child = spawn('bash', ['-lc', `export GH_CONFIG_DIR=/home/ubuntu/.config/gh; unset GH_TOKEN GITHUB_TOKEN; gh api -i user 2>/dev/null | tr -d '\\r' | grep -Eiq '^x-oauth-scopes:.*delete_repo'`], { stdio: 'ignore' });
    child.on('exit', code => resolve(code === 0));
    child.on('error', () => resolve(false));
  });
}

async function run() {
  if (await scopePresent()) {
    console.log('BACKEND_DELETE_REPO_SCOPE=ALREADY_PRESENT');
    return;
  }
  fs.writeFileSync(logFile, '', { mode: 0o600 });
  const out = fs.openSync(logFile, 'a');
  const refresh = spawn('script', ['-qefc', `bash ${refreshScript}`, '/dev/null'], {
    cwd: ROOT,
    env: { ...process.env, BROWSER: 'true', GH_CONFIG_DIR: '/home/ubuntu/.config/gh', GH_TOKEN: '', GITHUB_TOKEN: '' },
    stdio: ['ignore', out, out],
  });

  let deviceCode = '';
  const deadline = Date.now() + maxWaitMs;
  while (Date.now() < deadline) {
    const text = fs.readFileSync(logFile, 'utf8');
    const match = text.match(/\b[A-Z0-9]{4}-[A-Z0-9]{4}\b/);
    if (match) { deviceCode = match[0]; break; }
    if (refresh.exitCode !== null) break;
    await sleep(500);
  }
  if (!deviceCode) {
    if (refresh.exitCode === null) refresh.kill('SIGTERM');
    throw new Error('device_code_not_emitted');
  }

  const browser = spawn(process.execPath, [browserScript], {
    cwd: ROOT,
    env: { ...process.env, DISPLAY: process.env.DISPLAY || ':98', GITHUB_DEVICE_CODE: deviceCode },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  let browserOut = '';
  browser.stdout.on('data', chunk => { browserOut += String(chunk); });
  browser.stderr.on('data', chunk => { browserOut += String(chunk); });
  const browserCode = await new Promise((resolve, reject) => {
    browser.on('exit', resolve);
    browser.on('error', reject);
  });
  deviceCode = '';
  if (browserCode !== 0 || !/GITHUB_DEVICE_AUTH=PASS/.test(browserOut)) {
    if (refresh.exitCode === null) refresh.kill('SIGTERM');
    throw new Error('browser_device_authorization_failed');
  }

  let ok = false;
  for (let i = 0; i < 25; i += 1) {
    if (await scopePresent()) { ok = true; break; }
    await sleep(1000);
  }
  if (!ok) {
    if (refresh.exitCode === null) refresh.kill('SIGTERM');
    throw new Error('delete_repo_scope_not_verified');
  }

  if (refresh.exitCode === null) refresh.kill('SIGTERM');
  console.log('GITHUB_DEVICE_BROWSER_AUTH=PASS');
  console.log('BACKEND_DELETE_REPO_SCOPE=PASS');
}

try {
  await run();
} catch (error) {
  const reason = String(error?.message || 'unknown').replace(/[^A-Za-z0-9_.-]+/g, '_').slice(0, 120);
  console.error(`BACKEND_DELETE_REPO_SCOPE=FAIL reason=${reason}`);
  process.exitCode = 2;
} finally {
  try { fs.rmSync(logFile, { force: true }); } catch {}
}
