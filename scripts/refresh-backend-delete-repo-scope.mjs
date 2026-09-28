import { spawn } from 'node:child_process';

const ROOT = process.env.GITHUB_WORKSPACE || process.cwd();
const refreshScript = `${ROOT}/scripts/refresh-backend-delete-repo-scope.sh`;
const browserScript = `${ROOT}/scripts/github-device-auth-delete-repo.mjs`;
const maxWaitMs = 45_000;
const maxTranscriptChars = 65_536;

function sleep(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }

function scopePresent() {
  return new Promise(resolve => {
    const child = spawn('bash', ['-lc', `export GH_CONFIG_DIR=/home/ubuntu/.config/gh; unset GH_TOKEN GITHUB_TOKEN; gh api -i user 2>/dev/null | tr -d '\\r' | grep -Eiq '^x-oauth-scopes:.*delete_repo'`], { stdio: 'ignore' });
    child.on('exit', code => resolve(code === 0));
    child.on('error', () => resolve(false));
  });
}

function stripAnsi(text) {
  return String(text || '')
    .replace(/\x1B\[[0-?]*[ -/]*[@-~]/g, '')
    .replace(/\r/g, '');
}

function failureReason(transcript, exitCode) {
  const text = stripAnsi(transcript).toLowerCase();
  if (text.includes('not logged in')) return 'gh_not_logged_in';
  if (text.includes('could not prompt')) return 'gh_prompt_failed';
  if (text.includes('authentication timed out')) return 'gh_auth_timeout';
  if (text.includes('device flow not supported')) return 'gh_device_flow_unsupported';
  if (exitCode !== null) return `gh_refresh_exit_${exitCode}`;
  return 'device_code_not_emitted';
}

async function run() {
  if (await scopePresent()) {
    console.log('BACKEND_DELETE_REPO_SCOPE=ALREADY_PRESENT');
    return;
  }

  const refresh = spawn('script', ['-qefc', `bash ${refreshScript}`, '/dev/null'], {
    cwd: ROOT,
    env: { ...process.env, BROWSER: 'true', GH_CONFIG_DIR: '/home/ubuntu/.config/gh', GH_TOKEN: '', GITHUB_TOKEN: '' },
    stdio: ['pipe', 'pipe', 'pipe'],
  });

  let transcript = '';
  let deviceCode = '';
  const capture = chunk => {
    transcript = (transcript + String(chunk)).slice(-maxTranscriptChars);
    if (deviceCode) return;
    const match = stripAnsi(transcript).match(/\b[A-Z0-9]{4}-[A-Z0-9]{4}\b/i);
    if (match) deviceCode = match[0].toUpperCase();
  };
  refresh.stdout.on('data', capture);
  refresh.stderr.on('data', capture);

  refresh.stdin.write('\n\n\n');
  refresh.stdin.end();

  const deadline = Date.now() + maxWaitMs;
  while (Date.now() < deadline && !deviceCode && refresh.exitCode === null) {
    await sleep(250);
  }

  if (!deviceCode) {
    const reason = failureReason(transcript, refresh.exitCode);
    if (refresh.exitCode === null) refresh.kill('SIGTERM');
    transcript = '';
    throw new Error(reason);
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
  transcript = '';

  if (browserCode !== 0 || !/GITHUB_DEVICE_AUTH=PASS/.test(browserOut)) {
    if (refresh.exitCode === null) refresh.kill('SIGTERM');
    throw new Error('browser_device_authorization_failed');
  }

  let ok = false;
  for (let i = 0; i < 25; i += 1) {
    if (await scopePresent()) { ok = true; break; }
    if (refresh.exitCode !== null && refresh.exitCode !== 0) break;
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
}
