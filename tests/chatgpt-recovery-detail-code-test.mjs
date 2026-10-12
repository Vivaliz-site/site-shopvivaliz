import assert from 'node:assert/strict';

export function runRecoveryDetailTests({ outcomeStatusDetailCode }) {
  const cases = [
    ['bound browser session stream active or unconfirmed; deferred without reload or continuation', 'BOUND_STREAM_NOT_COMPLETE'],
    ['bound conversation UI is unavailable but canonical presence could not be confirmed; deferred without continuation', 'CONVERSATION_PRESENCE_UNCONFIRMED'],
    ['bound conversation exists canonically but no unfinished response is confirmed; deferred without continuation', 'CANONICAL_NO_UNFINISHED_RESPONSE'],
    ['passive reattach observed no assistant progress; active stream remains unconfirmed', 'ACTIVE_STREAM_AFTER_REATTACH'],
    ['browser session account mismatch or bound conversation mismatch', 'BOUND_SESSION_IDENTITY_MISMATCH'],
  ];
  for (const [detail, expected] of cases) {
    assert.equal(outcomeStatusDetailCode('STALLED_NOT_CONFIRMED', detail), expected);
  }
  assert.equal(outcomeStatusDetailCode('ERROR', 'untrusted diagnostic payload'), 'UNCLASSIFIED_RUNTIME_ERROR');
  console.log('RECOVERY_DETAIL_CODES_TEST=PASS');
}
if (import.meta.url === `file://${process.argv[1]}`) {
  runRecoveryDetailTests(await import('../scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs'));
}
