import assert from 'node:assert/strict';
import test from 'node:test';
import { safeImageFailure } from './production-image-audit-telemetry.mjs';

test('failed image diagnostics preserve host/status but never URL credentials or signed query', () => {
  const url = 'https://username:password@s3.amazonaws.com/catalog/image.webp?X-Amz-Signature=secret#hidden';
  const value = safeImageFailure({ url, status: 404 });
  assert.equal(value.host, 's3.amazonaws.com');
  assert.equal(value.status, 404);
  assert.equal(value.extension, 'webp');
  assert.match(value.pathSha256, /^[a-f0-9]{16}$/);
  assert.doesNotMatch(JSON.stringify(value), /username|password|Signature|secret|hidden|image\.webp/);
});

test('same resource retains a stable fingerprint regardless of signed query parameters', () => {
  const url = 'https://s3.amazonaws.com/catalog/image.webp';
  assert.equal(
    safeImageFailure({ url: url + '?signature=a' }).pathSha256,
    safeImageFailure({ url: url + '?signature=b' }).pathSha256,
  );
});

test('invalid URL and unknown network errors are sanitized', () => {
  const value = safeImageFailure({ url: 'secret-only', error: 'credential leaked', status: 503 });
  assert.deepEqual(value, { host: 'invalid-url', pathSha256: '', extension: '', status: 503, error: 'request-failed' });
  assert.equal(safeImageFailure({ url: 'https://example.com/p.png', error: 'net::ERR_ABORTED' }).error, 'net::ERR_ABORTED');
});
