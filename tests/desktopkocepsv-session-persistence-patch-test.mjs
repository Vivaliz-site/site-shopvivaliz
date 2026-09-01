import { mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { pathToFileURL, fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const patcher = path.join(root, 'scripts', 'patch-desktop-commander-session-persistence.mjs');
const temporary = await mkdtemp(path.join(os.tmpdir(), 'shopvivaliz-session-patch-test-'));
const deviceDir = path.join(temporary, 'dist', 'remote-device');
const deviceSource = path.join(deviceDir, 'device.js');
const original = `const os = { hostname: () => 'test-host' };
export class FakeDevice {
    constructor() {
        this.persistCalls = 0;
        this.releaseRefresh = null;
        this.remoteChannel = { client: { auth: { onAuthStateChange: (callback) => { this.refreshCallback = callback; } } } };
    }
    async savePersistedConfig() {
        this.persistCalls += 1;
        if (this.persistCalls === 1) return;
        return new Promise((resolve) => { this.releaseRefresh = resolve; });
    }
    async init() {            await this.savePersistedConfig();
            const deviceName = os.hostname();
            return deviceName;
    }
}`;

function runPatch(expectedState) {
  const result = spawnSync(process.execPath, [patcher, temporary], { encoding: 'utf8' });
  if (result.status !== 0 || !result.stdout.includes(`SESSION_REFRESH_PATCH=${expectedState}`)) {
    throw new Error(`patch state ${expectedState} failed: ${result.stderr || result.stdout}`);
  }
}

function assertNonBlockingSource(source) {
  for (const needle of ['TOKEN_REFRESHED', 'void this.savePersistedConfig()', 'SESSION_REFRESH_PERSIST_ATTEMPTED', 'SESSION_REFRESH_PERSIST_FAILED', '(v3)']) {
    if (!source.includes(needle)) throw new Error(`patched source missing ${needle}`);
  }
  if (source.includes('onAuthStateChange(async (event, refreshedSession)')) {
    throw new Error('refresh callback must not be async');
  }
}

try {
  await mkdir(deviceDir, { recursive: true });
  await writeFile(deviceSource, original, 'utf8');
  runPatch('applied');
  const patched = await readFile(deviceSource, 'utf8');
  assertNonBlockingSource(patched);

  const moduleUrl = pathToFileURL(deviceSource).href + `?t=${Date.now()}`;
  const { FakeDevice } = await import(moduleUrl);
  const device = new FakeDevice();
  await device.init();
  const callbackResult = device.refreshCallback('TOKEN_REFRESHED', { access_token: 'fresh' });
  if (callbackResult !== undefined) throw new Error('refresh callback blocked on persistence promise');
  if (device.persistCalls !== 2) throw new Error(`expected refresh persistence call, got ${device.persistCalls}`);
  if (typeof device.releaseRefresh !== 'function') throw new Error('refresh persistence promise was not started');
  device.releaseRefresh();
  await new Promise((resolve) => setImmediate(resolve));

  runPatch('already_applied');
  if ((await readFile(deviceSource, 'utf8')) !== patched) throw new Error('idempotent run changed patched source');

  const v2 = original.replace(
    '            await this.savePersistedConfig();\n            const deviceName = os.hostname();',
    `            await this.savePersistedConfig();
            // Persist refreshed credentials before a service restart can lose them (v2).
            this.remoteChannel.client.auth.onAuthStateChange(async (event, refreshedSession) => {
                if (event === 'TOKEN_REFRESHED' && refreshedSession?.access_token) {
                    await this.savePersistedConfig();
                    console.log('SESSION_REFRESH_PERSIST_ATTEMPTED');
                }
            });
            const deviceName = os.hostname();`
  );
  await writeFile(deviceSource, v2, 'utf8');
  runPatch('upgraded_v2');
  assertNonBlockingSource(await readFile(deviceSource, 'utf8'));

  const legacy = original.replace(
    '            await this.savePersistedConfig();\n            const deviceName = os.hostname();',
    `            await this.savePersistedConfig();
            // Persist refreshed credentials before a service restart can lose them.
            this.remoteChannel.client.auth.onAuthStateChange((event, refreshedSession) => {
                if (event === 'TOKEN_REFRESHED' && refreshedSession?.access_token) {
                    void this.savePersistedConfig();
                }
            });
            const deviceName = os.hostname();`
  );
  await writeFile(deviceSource, legacy, 'utf8');
  runPatch('upgraded_legacy');
  assertNonBlockingSource(await readFile(deviceSource, 'utf8'));

  console.log('desktopkocepsv-session-persistence-patch: ok');
} finally {
  await rm(temporary, { recursive: true, force: true });
}