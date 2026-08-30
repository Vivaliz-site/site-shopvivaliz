import { mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const patcher = path.join(root, 'scripts', 'patch-desktop-commander-session-persistence.mjs');
const temporary = await mkdtemp(path.join(os.tmpdir(), 'shopvivaliz-session-patch-test-'));
const deviceDir = path.join(temporary, 'dist', 'remote-device');
const deviceSource = path.join(deviceDir, 'device.js');
const original = `async init() {
            await this.savePersistedConfig();
            const deviceName = os.hostname();
}`;

try {
  await mkdir(deviceDir, { recursive: true });
  await writeFile(deviceSource, original, 'utf8');
  const first = spawnSync(process.execPath, [patcher, temporary], { encoding: 'utf8' });
  if (first.status !== 0 || !first.stdout.includes('SESSION_REFRESH_PATCH=applied')) {
    throw new Error(`first patch failed: ${first.stderr || first.stdout}`);
  }
  const patched = await readFile(deviceSource, 'utf8');
  for (const needle of ['TOKEN_REFRESHED', 'await this.savePersistedConfig()', 'SESSION_REFRESH_PERSIST_ATTEMPTED', '(v2)']) {
    if (!patched.includes(needle)) throw new Error(`patched source missing ${needle}`);
  }
  const second = spawnSync(process.execPath, [patcher, temporary], { encoding: 'utf8' });
  if (second.status !== 0 || !second.stdout.includes('SESSION_REFRESH_PATCH=already_applied')) {
    throw new Error(`idempotency failed: ${second.stderr || second.stdout}`);
  }
  if ((await readFile(deviceSource, 'utf8')) !== patched) throw new Error('idempotent run changed patched source');

  const legacy = patched
    .replace('Persist refreshed credentials before a service restart can lose them (v2).', 'Persist refreshed credentials before a service restart can lose them.')
    .replace('onAuthStateChange(async (event, refreshedSession)', 'onAuthStateChange((event, refreshedSession)')
    .replace('await this.savePersistedConfig();\n                    console.log(\'SESSION_REFRESH_PERSIST_ATTEMPTED\');', 'void this.savePersistedConfig();');
  await writeFile(deviceSource, legacy, 'utf8');
  const upgrade = spawnSync(process.execPath, [patcher, temporary], { encoding: 'utf8' });
  if (upgrade.status !== 0 || !upgrade.stdout.includes('SESSION_REFRESH_PATCH=upgraded')) {
    throw new Error(`legacy upgrade failed: ${upgrade.stderr || upgrade.stdout}`);
  }
  console.log('desktopkocepsv-session-persistence-patch: ok');
} finally {
  await rm(temporary, { recursive: true, force: true });
}
