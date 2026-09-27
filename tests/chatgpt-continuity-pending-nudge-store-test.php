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

cgnAssert($store->enqueue('task-1', 'Vivaliz-site/site-shopvivaliz', '2026-09-27T19:00:00Z'), 'First enqueue must succeed.');
cgnAssert(!$store->enqueue('task-1', 'Vivaliz-site/site-shopvivaliz', '2026-09-27T19:00:05Z'), 'Duplicate enqueue for the same PENDING task_id must be rejected (idempotent).');

$pulled = $store->pullOldest();
cgnAssert($pulled !== null, 'pullOldest must return the pending nudge.');
cgnSame('task-1', $pulled['task_id'], 'Pulled nudge must be task-1.');
cgnSame('CLAIMED', $pulled['status'], 'Pull must transition status to CLAIMED.');

cgnSame(null, $store->pullOldest(), 'A second pull with no other pending/abandoned nudge must return null (single active claim).');

cgnAssert(!$store->enqueue('task-1', 'Vivaliz-site/site-shopvivaliz', '2026-09-27T19:05:00Z'), 'Enqueue while CLAIMED must still be rejected as duplicate.');

cgnAssert($store->recordResult('task-1', 'SENT', 'typed continue and clicked send'), 'Recording a result for an existing task_id must succeed.');
cgnAssert(!$store->recordResult('task-does-not-exist', 'SENT', null), 'Recording a result for an unknown task_id must fail.');

$status = $store->status('task-1');
cgnSame('SENT', $status['status'], 'Status after recordResult must reflect SENT.');
cgnAssert($status['resolved_at'] !== null, 'resolved_at must be set after recordResult.');

cgnAssert($store->enqueue('task-1', 'Vivaliz-site/site-shopvivaliz', '2026-09-27T19:10:00Z'), 'A fresh enqueue after resolution (SENT is terminal) must be allowed again for a later interruption of the same task.');

$statusAfterReenqueue = $store->status('task-1');
cgnSame('PENDING', $statusAfterReenqueue['status'], 'Re-enqueue must reset the SAME row back to PENDING, not leave the old SENT row as the first match.');
cgnSame(null, $statusAfterReenqueue['resolved_at'], 'A reset row must clear resolved_at.');

try {
    $store->recordResult('task-1', 'NOT_A_REAL_STATUS', null);
    throw new RuntimeException('recordResult must reject an unsupported status.');
} catch (InvalidArgumentException) {
    // expected
}

unlink($tmp);
rmdir(dirname($tmp));

echo "CHATGPT_CONTINUITY_PENDING_NUDGE_STORE_TEST=PASS\n";
