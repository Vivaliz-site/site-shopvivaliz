import { readFile, rename, stat, writeFile } from 'node:fs/promises';
import path from 'node:path';

const packageRoot = process.argv[2];
if (!packageRoot) {
  throw new Error('Desktop Commander package root is required');
}

const deviceSource = path.join(packageRoot, 'dist', 'remote-device', 'device.js');
const source = await readFile(deviceSource, 'utf8');
const sentinel = 'Persist refreshed credentials before a service restart can lose them (v2).';

if (source.includes(sentinel)) {
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
const replacement = `            await this.savePersistedConfig();
            // Persist refreshed credentials before a service restart can lose them (v2).
            this.remoteChannel.client.auth.onAuthStateChange(async (event, refreshedSession) => {
                if (event === 'TOKEN_REFRESHED' && refreshedSession?.access_token) {
                    await this.savePersistedConfig();
                    console.log('SESSION_REFRESH_PERSIST_ATTEMPTED');
                }
            });
            const deviceName = os.hostname();`;

const legacyOccurrences = source.split(legacyPatch).length - 1;
const markerOccurrences = source.split(marker).length - 1;
if (legacyOccurrences + markerOccurrences !== 1) {
  throw new Error(`Unsupported Desktop Commander source: expected one persistence marker, found ${legacyOccurrences + markerOccurrences}`);
}

const metadata = await stat(deviceSource);
const temporary = `${deviceSource}.shopvivaliz-${process.pid}.tmp`;
const target = legacyOccurrences === 1 ? legacyPatch : marker;
await writeFile(temporary, source.replace(target, replacement), { mode: metadata.mode });
await rename(temporary, deviceSource);
console.log(legacyOccurrences === 1 ? 'SESSION_REFRESH_PATCH=upgraded' : 'SESSION_REFRESH_PATCH=applied');
