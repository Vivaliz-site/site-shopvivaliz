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
        'SENT_UNCONFIRMED',
        'PROGRESS_CONFIRMED',
        'STALLED_NOT_CONFIRMED',
        'CONVERSATION_NOT_FOUND',
        'ERROR',
    ];

    /** Claims older than this are treated as abandoned and become pullable again. */
    private const CLAIM_TIMEOUT_SECONDS = 300;
    private const DEFAULT_RESOLVED_RETENTION_SECONDS = 86400;

    private string $path;
    private string $archivePath;
    private int $resolvedRetentionSeconds;

    public function __construct(string $path, ?string $archivePath = null, int $resolvedRetentionSeconds = self::DEFAULT_RESOLVED_RETENTION_SECONDS)
    {
        $this->path = $path;
        $this->archivePath = $archivePath ?? preg_replace('/\\.json$/', '-archive.jsonl', $path) ?: ($path . '.archive.jsonl');
        $this->resolvedRetentionSeconds = max(0, $resolvedRetentionSeconds);
    }

    private function archiveExpiredResolved(array $nudges): array
    {
        $now = time();
        $keep = [];
        $archive = [];
        foreach ($nudges as $row) {
            if (!is_array($row)) {
                $archive[] = ['_archive_reason' => 'malformed_non_array'];
                continue;
            }
            $status = strtoupper(trim((string)($row['status'] ?? '')));
            if (in_array($status, ['PENDING', 'CLAIMED'], true)) {
                $keep[] = $row;
                continue;
            }
            $resolvedAt = isset($row['resolved_at']) ? strtotime((string)$row['resolved_at']) : false;
            if ($resolvedAt === false || ($now - $resolvedAt) < $this->resolvedRetentionSeconds) {
                $keep[] = $row;
                continue;
            }
            $row['_archive_reason'] = 'resolved_retention_elapsed';
            $archive[] = $row;
        }

        if ($archive !== []) {
            $handle = fopen($this->archivePath, 'ab');
            if ($handle === false) {
                throw new RuntimeException('cannot open pending-nudges archive');
            }
            try {
                foreach ($archive as $row) {
                    fwrite($handle, json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL);
                }
                fflush($handle);
                if (function_exists('fsync')) {
                    fsync($handle);
                }
            } finally {
                fclose($handle);
            }
        }

        return $keep;
    }

    private function archivedStatus(string $taskId): ?array
    {
        if (!is_file($this->archivePath) || !is_readable($this->archivePath)) {
            return null;
        }
        $lines = @file($this->archivePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return null;
        }
        for ($i = count($lines) - 1; $i >= 0; --$i) {
            $row = json_decode($lines[$i], true);
            if (is_array($row) && (string)($row['task_id'] ?? '') === $taskId) {
                unset($row['_archive_reason']);
                return $row;
            }
        }
        return null;
    }

    private function archiveRowCount(): int
    {
        if (!is_file($this->archivePath) || !is_readable($this->archivePath)) {
            return 0;
        }
        $lines = @file($this->archivePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return is_array($lines) ? count($lines) : 0;
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
            $nudges = $this->archiveExpiredResolved($nudges);

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
    public function enqueue(string $taskId, string $repository, string $requestedAt, string $conversationId = ''): bool
    {
        return (bool)$this->withLock(function (array $nudges) use ($taskId, $repository, $requestedAt, $conversationId) {
            foreach ($nudges as $index => $row) {
                if ($row['task_id'] !== $taskId) {
                    continue;
                }
                if (in_array($row['status'], ['PENDING', 'CLAIMED'], true)) {
                    if ($conversationId !== '' && (string)($row['conversation_id'] ?? '') === '') {
                        $nudges[$index]['conversation_id'] = $conversationId;
                    }
                    return ['nudges' => $nudges, 'return' => false];
                }
                $nudges[$index] = [
                    'task_id' => $taskId,
                    'repository' => $repository,
                    'conversation_id' => $conversationId !== '' ? $conversationId : null,
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
                'conversation_id' => $conversationId !== '' ? $conversationId : null,
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

    /**
     * Runtime/CDP errors must remain diagnosable without persisting browser,
     * prompt, cookie, or token text that an upstream error might include.
     *
     * @return array{code: string, sha256: string|null}
     */
    private static function safeDetailDiagnostic(?string $detail): array
    {
        $raw = trim((string)$detail);
        if ($raw === '') {
            return ['code' => 'NONE', 'sha256' => null];
        }

        $normalized = strtolower($raw);
        $code = match (true) {
            str_contains($normalized, 'composer/send-button remained unavailable after bounded reattach') => 'COMPOSER_UNAVAILABLE_AFTER_REATTACH',
            str_contains($normalized, 'composer found but send failed after bounded reattach') => 'SEND_FAILED_AFTER_REATTACH',
            str_contains($normalized, 'transmission error persisted and composer was unavailable after reattach') => 'TRANSMISSION_COMPOSER_UNAVAILABLE',
            str_contains($normalized, 'transmission error persisted and retry send failed') => 'TRANSMISSION_RETRY_SEND_FAILED',
            str_contains($normalized, 'transmission error persisted after bounded recovery retry') => 'TRANSMISSION_PERSISTED_AFTER_RETRY',
            str_contains($normalized, 'multiple open chatgpt conversation tabs found') => 'AMBIGUOUS_CONVERSATION_TARGET',
            str_contains($normalized, 'cdp endpoint unreachable') => 'CDP_ENDPOINT_UNREACHABLE',
            default => 'UNCLASSIFIED_RUNTIME_ERROR',
        };
        return ['code' => $code, 'sha256' => hash('sha256', $raw)];
    }

    public function recordResult(string $taskId, string $status, ?string $detail): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('unsupported nudge result status');
        }
        $diagnostic = $status === 'PROGRESS_CONFIRMED'
            ? [
                'code' => 'PROGRESS_CONFIRMED',
                'sha256' => trim((string)$detail) !== '' ? hash('sha256', trim((string)$detail)) : null,
            ]
            : self::safeDetailDiagnostic($detail);
        return (bool)$this->withLock(function (array $nudges) use ($taskId, $status, $diagnostic) {
            foreach ($nudges as $index => $row) {
                if ($row['task_id'] === $taskId) {
                    $nudges[$index]['status'] = $status;
                    $nudges[$index]['resolved_at'] = gmdate(DATE_ATOM);
                    unset($nudges[$index]['detail']);
                    $nudges[$index]['detail_code'] = $diagnostic['code'];
                    $nudges[$index]['detail_sha256'] = $diagnostic['sha256'];
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
                if ((string)($row['task_id'] ?? '') === $taskId) {
                    return ['nudges' => $nudges, 'return' => $row];
                }
            }
            return ['nudges' => $nudges, 'return' => $this->archivedStatus($taskId)];
        });
    }

    public function summary(): array
    {
        return $this->withLock(function (array $nudges) {
            $counts = [
                'total' => count($nudges),
                'pending' => 0,
                'claimed' => 0,
                'resolved_recent' => 0,
                'invalid' => 0,
                'archive_rows' => $this->archiveRowCount(),
            ];
            foreach ($nudges as $row) {
                if (!is_array($row)) {
                    ++$counts['invalid'];
                    continue;
                }
                $status = strtoupper(trim((string)($row['status'] ?? '')));
                if ($status === 'PENDING') {
                    ++$counts['pending'];
                } elseif ($status === 'CLAIMED') {
                    ++$counts['claimed'];
                } elseif (in_array($status, self::STATUSES, true)) {
                    ++$counts['resolved_recent'];
                } else {
                    ++$counts['invalid'];
                }
            }
            $counts['active'] = $counts['pending'] + $counts['claimed'];
            $counts['certified'] = $counts['invalid'] === 0
                && $counts['total'] === ($counts['active'] + $counts['resolved_recent']);
            return ['nudges' => $nudges, 'return' => $counts];
        });
    }
}
