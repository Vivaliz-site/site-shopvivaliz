<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/chatgpt-continuity/PendingNudgeStore.php';

function cgnAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function cgnSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

$tmp = sys_get_temp_dir() . '/chatgpt-continuity-test-' . bin2hex(random_bytes(6)) . '/pending-nudges.json';

$store = new SvChatgptContinuityPendingNudgeStore($tmp);

cgnAssert($store->enqueue('task-1', 'Vivaliz-site/site-shopvivaliz', '2026-09-27T19:00:00Z', '11111111-2222-3333-4444-555555555555'), 'First enqueue must succeed.');
cgnAssert(!$store->enqueue('task-1', 'Vivaliz-site/site-shopvivaliz', '2026-09-27T19:00:05Z'), 'Duplicate enqueue for the same PENDING task_id must be rejected (idempotent).');

$pulled = $store->pullOldest();
cgnAssert($pulled !== null, 'pullOldest must return the pending nudge.');
cgnSame('task-1', $pulled['task_id'], 'Pulled nudge must be task-1.');
cgnSame('11111111-2222-3333-4444-555555555555', $pulled['conversation_id'], 'Explicit conversation binding must survive enqueue and claim.');
cgnSame('CLAIMED', $pulled['status'], 'Pull must transition status to CLAIMED.');

cgnSame(null, $store->pullOldest(), 'A second pull with no other pending/abandoned nudge must return null (single active claim).');

cgnAssert(!$store->enqueue('task-1', 'Vivaliz-site/site-shopvivaliz', '2026-09-27T19:05:00Z'), 'Enqueue while CLAIMED must still be rejected as duplicate.');

cgnAssert($store->recordResult('task-1', 'SENT_UNCONFIRMED', 'typed continue; no assistant progress observed'), 'Unconfirmed send must be a valid retryable result.');
$statusUnconfirmed = $store->status('task-1');
cgnSame('SENT_UNCONFIRMED', $statusUnconfirmed['status'], 'A click without assistant progress must not be terminal success.');

// Browser/CDP failures can contain unexpected runtime text. Persist only a
// fixed diagnostic category and a non-reversible correlation hash.
cgnAssert($store->recordResult('task-1', 'ERROR', 'composer found but send failed after bounded reattach unexpected-runtime-text'), 'Error result must be recordable.');
$sanitizedError = $store->status('task-1');
cgnSame('SEND_FAILED_AFTER_REATTACH', $sanitizedError['detail_code'], 'Known browser failure must retain its safe diagnostic class.');
cgnAssert(preg_match('/^[a-f0-9]{64}$/', (string)($sanitizedError['detail_sha256'] ?? '')) === 1, 'Error detail must retain only a SHA-256 correlation value.');
cgnAssert(!isset($sanitizedError['detail']), 'Raw worker detail must never be returned from the durable queue.');

cgnAssert($store->recordResult('task-1', 'PROGRESS_CONFIRMED', 'assistant output advanced'), 'Confirmed assistant progress must be a valid result.');
cgnAssert(!$store->recordResult('task-does-not-exist', 'PROGRESS_CONFIRMED', null), 'Recording a result for an unknown task_id must fail.');

$status = $store->status('task-1');
cgnSame('PROGRESS_CONFIRMED', $status['status'], 'Status after confirmed progress must reflect PROGRESS_CONFIRMED.');
cgnAssert($status['resolved_at'] !== null, 'resolved_at must be set after recordResult.');

cgnAssert($store->enqueue('task-1', 'Vivaliz-site/site-shopvivaliz', '2026-09-27T19:10:00Z'), 'A fresh enqueue after PROGRESS_CONFIRMED must be allowed again for a later interruption of the same task.');

$statusAfterReenqueue = $store->status('task-1');
cgnSame('PENDING', $statusAfterReenqueue['status'], 'Re-enqueue must reset the SAME row back to PENDING, not leave the old SENT row as the first match.');
cgnSame(null, $statusAfterReenqueue['resolved_at'], 'A reset row must clear resolved_at.');

try {
    $store->recordResult('task-1', 'NOT_A_REAL_STATUS', null);
    throw new RuntimeException('recordResult must reject an unsupported status.');
} catch (InvalidArgumentException) {
    // expected
}

$historyTmp = sys_get_temp_dir() . '/chatgpt-continuity-history-test-' . bin2hex(random_bytes(6)) . '/pending-nudges.json';
$historyArchive = dirname($historyTmp) . '/pending-nudges-archive.jsonl';
$historyStore = new SvChatgptContinuityPendingNudgeStore($historyTmp, $historyArchive, 0);
cgnAssert($historyStore->enqueue('task-history', 'Vivaliz-site/site-shopvivaliz', '2026-09-27T20:00:00Z'), 'History enqueue must succeed.');
cgnAssert($historyStore->pullOldest() !== null, 'History task must be claimable.');
cgnAssert($historyStore->recordResult('task-history', 'PROGRESS_CONFIRMED', 'done'), 'History result must be recorded.');

$historySummary = $historyStore->summary();
cgnSame(0, $historySummary['total'], 'Resolved rows past retention must leave the hot store.');
cgnSame(0, $historySummary['active'], 'No active nudge may be manufactured by compaction.');
cgnSame(1, $historySummary['archive_rows'], 'Resolved history must move to the archive exactly once.');
cgnAssert($historySummary['certified'] === true, 'Compacted bridge queue must certify.');

$archivedStatus = $historyStore->status('task-history');
cgnAssert($archivedStatus !== null, 'Archived status must remain queryable for compatibility.');
cgnSame('PROGRESS_CONFIRMED', $archivedStatus['status'], 'Archived status must preserve the worker result.');

cgnAssert($historyStore->enqueue('task-history', 'Vivaliz-site/site-shopvivaliz', '2026-09-27T20:05:00Z'), 'A later interruption may reuse an archived task_id.');
$historyActive = $historyStore->status('task-history');
cgnSame('PENDING', $historyActive['status'], 'Active state must take precedence over archived history.');

@unlink($tmp);
@rmdir(dirname($tmp));
@unlink($historyTmp);
@unlink($historyArchive);
@rmdir(dirname($historyTmp));

echo "CHATGPT_CONTINUITY_PENDING_NUDGE_STORE_TEST=PASS\n";
