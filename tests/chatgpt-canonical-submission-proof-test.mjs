import assert from 'node:assert/strict';
import {
  canonicalSubmissionOutcome,
  canonicalContinuationSubmissionState,
} from '../scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs';

assert.deepEqual(
  canonicalSubmissionOutcome(false, { http_status: 200, confirmed: false }),
  { result_status: 'STALLED_NOT_CONFIRMED', canonical_submission_confirmed: false },
);
assert.deepEqual(
  canonicalSubmissionOutcome(false, { http_status: 200, confirmed: true }),
  { result_status: 'SENT_UNCONFIRMED', canonical_submission_confirmed: true },
);
assert.deepEqual(
  canonicalSubmissionOutcome(false, { http_status: 429, confirmed: null }),
  { result_status: 'SENT_UNCONFIRMED', canonical_submission_confirmed: null },
);
assert.deepEqual(
  canonicalSubmissionOutcome(true, { http_status: 200, confirmed: true }),
  { result_status: 'PROGRESS_CONFIRMED', canonical_submission_confirmed: true },
);

const absent = await canonicalContinuationSubmissionState(
  { evaluate: async source => {
      assert.match(source, /canonical-continuation-submission-proof/);
      assert.match(source, /const baselineNodeId="baseline-node";/);
      assert.match(source, /const expected="continue";/);
      assert.equal(source.includes(String.fromCharCode(36,123) + 'JSON.stringify'), false);
      return { http_status: 200, confirmed: false };
    } },
  { http_status: 200, node_id: 'baseline-node' },
);
assert.deepEqual(absent, { http_status: 200, confirmed: false });

console.log('CANONICAL_SUBMISSION_PROOF_TEST=PASS');
