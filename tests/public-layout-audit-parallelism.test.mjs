import assert from 'node:assert/strict';
import test from 'node:test';

import { mapWithConcurrency, resolveAuditConcurrency } from '../scripts/lib/audit-concurrency.mjs';

test('resolveAuditConcurrency defaults to 6 and accepts a positive override', () => {
  assert.equal(resolveAuditConcurrency(undefined), 6);
  assert.equal(resolveAuditConcurrency('3'), 3);
  assert.equal(resolveAuditConcurrency('0'), 6);
  assert.equal(resolveAuditConcurrency('invalid'), 6);
});

test('mapWithConcurrency caps active work while preserving result order', async () => {
  const items = Array.from({ length: 12 }, (_, index) => index);
  let active = 0;
  let peak = 0;

  const results = await mapWithConcurrency(items, 3, async (item) => {
    active += 1;
    peak = Math.max(peak, active);
    await new Promise((resolve) => setTimeout(resolve, 5 + ((11 - item) % 4)));
    active -= 1;
    return item * 2;
  });

  assert.equal(peak, 3);
  assert.deepEqual(results, items.map((item) => item * 2));
});
