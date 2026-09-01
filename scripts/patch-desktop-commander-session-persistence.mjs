import { readFile, rename, stat, writeFile } from 'node:fs/promises';
import path from 'node:path';

const packageRoot = process.argv[2];
if (!packageRoot) {
  throw new Error('Desktop Commander package root is required');
}

const deviceSource = path.join(packageRoot, 'dist', 'remote-device', 'device.js');
const source = await readFile(deviceSource, 'utf8');
const sentinelV3 = 'Persist refreshed credentials without blocking the auth refresh chain (v3).';

if (source.includes(sentinelV3)) {
  console.log('SESSION_REFRESH_PATCH=already_applied');
  process.exit(0);
}

const marker = `            await this.savePersistedConfig();
            const deviceName = os.hostname();`;
const legacyPatch = `            await this.savePersistedConfig();
            // Persist refreshed credentials before a service restart can lose them.
            this.remoteChannel.client.auth.onAuthStateChange((event, refreshedSession) => {
                if (event === 'TOKEN_REFRESHED' && refreshedSession?.access_token) {
                    void this.savePersistedConfig();
                }
            });
            const deviceName = os.hostname();`;
const v2Patch = `            await this.savePersistedConfig();
            // Persist refreshed credentials before a service restart can lose them (v2).
            this.remoteChannel.client.auth.onAuthStateChange(async (event, refreshedSession) => {
                if (event === 'TOKEN_REFRESHED' && refreshedSession?.access_token) {
                    await this.savePersistedConfig();
                    console.log('SESSION_REFRESH_PERSIST_ATTEMPTED');
                }
            });
            const deviceName = os.hostname();`;
const replacement = `            await this.savePersistedConfig();
            // Persist refreshed credentials without blocking the auth refresh chain (v3).
            this.remoteChannel.client.auth.onAuthStateChange((event, refreshedSession) => {
                if (event === 'TOKEN_REFRESHED' && refreshedSession?.access_token) {
                    void this.savePersistedConfig()
                        .then(() => console.log('SESSION_REFRESH_PERSIST_ATTEMPTED'))
                        .catch((error) => {
                            console.error('SESSION_REFRESH_PERSIST_FAILED', error instanceof Error ? error.message : String(error));
                        });
                }
            });
            const deviceName = os.hostname();`;

const candidates = [
  { source: v2Patch, state: 'upgraded_v2' },
  { source: legacyPatch, state: 'upgraded_legacy' },
  { source: marker, state: 'applied' },
];
const matches = candidates.flatMap((candidate) => {
  const occurrences = source.split(candidate.source).length - 1;
  return Array.from({ length: occurrences }, () => candidate);
});
if (matches.length !== 1) {
  throw new Error(`Unsupported Desktop Commander source: expected one persistence marker, found ${matches.length}`);
}

const match = matches[0];
const metadata = await stat(deviceSource);
const temporary = `${deviceSource}.shopvivaliz-${process.pid}.tmp`;
await writeFile(temporary, source.replace(match.source, replacement), { mode: metadata.mode });
await rename(temporary, deviceSource);
console.log(`SESSION_REFRESH_PATCH=${match.state}`);
