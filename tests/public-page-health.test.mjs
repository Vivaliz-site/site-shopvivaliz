import test from 'node:test';
import assert from 'node:assert/strict';
import { reportableResourceFailure } from '../scripts/lib/public-page-health.mjs';

const baseUrl = 'https://shopvivaliz.com.br';

test('reports same-origin core resource failures', () => {
  assert.equal(reportableResourceFailure({ url: `${baseUrl}/app.js`, status: 404, resourceType: 'script', baseUrl }), true);
  assert.equal(reportableResourceFailure({ url: `${baseUrl}/image.webp`, status: 500, resourceType: 'image', baseUrl }), true);
});

test('ignores successful, external and API/background responses', () => {
  assert.equal(reportableResourceFailure({ url: `${baseUrl}/app.js`, status: 200, resourceType: 'script', baseUrl }), false);
  assert.equal(reportableResourceFailure({ url: 'https://cdn.example.com/app.js', status: 404, resourceType: 'script', baseUrl }), false);
  assert.equal(reportableResourceFailure({ url: `${baseUrl}/api/cart`, status: 500, resourceType: 'xhr', baseUrl }), false);
});
