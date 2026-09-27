<?php

declare(strict_types=1);

/**
 * Durable file-backed queue of "nudge the user's real ChatGPT conversation"
 * requests. The A1 host (Linux, no access to the user's personal browser
 * session) enqueues; a Windows worker driving the already-logged-in browser
 * via CDP (scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs)
 * pulls and reports back. Volume is inherently tiny (at most one entry per
 * interrupted continuity task), so a single JSON file under flock is enough;
 * no database is warranted.
 */
final class SvChatgptContinuityPendingNudgeStore
{
    private const STATUSES = [
        'PENDING',
        'CLAIMED',
        'SENT',
        'STALLED_NOT_CONFIRMED',
        'CONVERSATION_NOT_FOUND',
        'ERROR',
    ];

    /** Claims older than this are treated as abandoned and become pullable again. */
    private const CLAIM_TIMEOUT_SECONDS = 300;

    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    private function withLock(callable $mutator): mixed
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('cannot create chatgpt-continuity storage directory');
        }
        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('cannot open pending-nudges store');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('cannot lock pending-nudges store');
            }
            $raw = stream_get_contents($handle);
            $data = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
            $nudges = is_array($data) && isset($data['nudges']) && is_array($data['nudges']) ? $data['nudges'] : [];

            $result = $mutator($nudges);
            $nudges = $result['nudges'];

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode(['nudges' => array_values($nudges)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($handle);
            return $result['return'] ?? null;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Idempotent upsert keyed by task_id: an active (PENDING/CLAIMED) row is
     * never duplicated; a resolved row is reset back to PENDING in place
     * (a later, separate interruption of the same task) rather than
     * appended as a second row, which would leave status()/pullOldest()
     * seeing stale historical data ahead of the current one.
     */
    public function enqueue(string $taskId, string $repository, string $requestedAt): bool
    {
        return (bool)$this->withLock(function (array $nudges) use ($taskId, $repository, $requestedAt) {
            foreach ($nudges as $index => $row) {
                if ($row['task_id'] !== $taskId) {
                    continue;
                }
                if (in_array($row['status'], ['PENDING', 'CLAIMED'], true)) {
                    return ['nudges' => $nudges, 'return' => false];
                }
                $nudges[$index] = [
                    'task_id' => $taskId,
                    'repository' => $repository,
                    'requested_at' => $requestedAt,
                    'status' => 'PENDING',
                    'claimed_at' => null,
                    'resolved_at' => null,
                    'detail' => null,
                ];
                return ['nudges' => $nudges, 'return' => true];
            }
            $nudges[] = [
                'task_id' => $taskId,
                'repository' => $repository,
                'requested_at' => $requestedAt,
                'status' => 'PENDING',
                'claimed_at' => null,
                'resolved_at' => null,
                'detail' => null,
            ];
            return ['nudges' => $nudges, 'return' => true];
        });
    }

    /** Claims the oldest PENDING (or abandoned CLAIMED) nudge for the worker to act on. */
    public function pullOldest(): ?array
    {
        return $this->withLock(function (array $nudges) {
            $now = time();
            $claimedIndex = null;
            foreach ($nudges as $index => $row) {
                if ($row['status'] === 'PENDING') {
                    $claimedIndex = $index;
                    break;
                }
                if ($row['status'] === 'CLAIMED') {
                    $claimedAt = isset($row['claimed_at']) ? strtotime((string)$row['claimed_at']) : false;
                    if ($claimedAt !== false && ($now - $claimedAt) > self::CLAIM_TIMEOUT_SECONDS) {
                        $claimedIndex = $index;
                        break;
                    }
                }
            }
            if ($claimedIndex === null) {
                return ['nudges' => $nudges, 'return' => null];
            }
            $nudges[$claimedIndex]['status'] = 'CLAIMED';
            $nudges[$claimedIndex]['claimed_at'] = gmdate(DATE_ATOM);
            return ['nudges' => $nudges, 'return' => $nudges[$claimedIndex]];
        });
    }

    public function recordResult(string $taskId, string $status, ?string $detail): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('unsupported nudge result status');
        }
        return (bool)$this->withLock(function (array $nudges) use ($taskId, $status, $detail) {
            foreach ($nudges as $index => $row) {
                if ($row['task_id'] === $taskId) {
                    $nudges[$index]['status'] = $status;
                    $nudges[$index]['resolved_at'] = gmdate(DATE_ATOM);
                    $nudges[$index]['detail'] = $detail !== null ? mb_substr($detail, 0, 500, 'UTF-8') : null;
                    return ['nudges' => $nudges, 'return' => true];
                }
            }
            return ['nudges' => $nudges, 'return' => false];
        });
    }

    public function status(string $taskId): ?array
    {
        return $this->withLock(function (array $nudges) use ($taskId) {
            foreach ($nudges as $row) {
                if ($row['task_id'] === $taskId) {
                    return ['nudges' => $nudges, 'return' => $row];
                }
            }
            return ['nudges' => $nudges, 'return' => null];
        });
    }
}
