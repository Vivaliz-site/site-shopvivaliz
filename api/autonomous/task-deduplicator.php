<?php
declare(strict_types=1);

/**
 * Task Deduplication System
 * Requirement 37: Prevent duplicate tasks in queue
 * Requirement 38: Detect and handle orphan tasks
 */

class TaskDeduplicator
{
    private const DEDUP_LOG = __DIR__ . '/../../logs/autonomous/deduplication.jsonl';

    /**
     * Check for duplicates before queuing
     */
    public static function checkDuplicate(array $newTask): array
    {
        $queueFile = __DIR__ . '/../../tasks-queue.json';
        if (!file_exists($queueFile)) {
            return ['is_duplicate' => false];
        }

        $queue = json_decode(file_get_contents($queueFile), true) ?? [];
        $existingTasks = $queue['tasks'] ?? [];

        // Compare with existing tasks
        foreach ($existingTasks as $existing) {
            // Same problem = duplicate
            if (self::compareTasks($newTask, $existing)) {
                return [
                    'is_duplicate' => true,
                    'duplicate_of' => $existing['id'] ?? 'unknown',
                    'existing_status' => $existing['status'] ?? 'unknown'
                ];
            }
        }

        return ['is_duplicate' => false];
    }

    /**
     * Compare two tasks for duplication
     */
    private static function compareTasks(array $t1, array $t2): bool
    {
        $title1 = strtolower(trim($t1['title'] ?? ''));
        $title2 = strtolower(trim($t2['title'] ?? ''));

        if (levenshtein($title1, $title2) < 5) {
            return true;
        }

        $affected1 = array_slice($t1['reserved_files'] ?? [], 0, 3);
        $affected2 = array_slice($t2['reserved_files'] ?? [], 0, 3);

        if (count(array_intersect($affected1, $affected2)) > 1) {
            return true;
        }

        return false;
    }

    /**
     * Link related tasks
     */
    public static function linkTasks(array $tasks): void
    {
        foreach ($tasks as $task) {
            $related = [];
            foreach ($tasks as $other) {
                if ($task['id'] !== $other['id'] && self::isRelated($task, $other)) {
                    $related[] = $other['id'];
                }
            }

            if (!empty($related)) {
                $task['related_tasks'] = array_values(array_unique($related));
            }
        }
    }

    /**
     * Check if tasks are related
     */
    private static function isRelated(array $t1, array $t2): bool
    {
        // Same files
        $files1 = array_slice($t1['reserved_files'] ?? [], 0, 2);
        $files2 = array_slice($t2['reserved_files'] ?? [], 0, 2);
        if (!empty(array_intersect($files1, $files2))) {
            return true;
        }

        // Related root causes
        $cause1 = strtolower($t1['root_cause'] ?? '');
        $cause2 = strtolower($t2['root_cause'] ?? '');
        if (!empty($cause1) && !empty($cause2) && levenshtein($cause1, $cause2) < 10) {
            return true;
        }

        return false;
    }

    /**
     * Log deduplication action
     */
    public static function logDuplicate(array $newTask, string $duplicateOf): void
    {
        $dir = dirname(self::DEDUP_LOG);
        @mkdir($dir, 0755, true);

        file_put_contents(
            self::DEDUP_LOG,
            json_encode([
                'timestamp' => date('c'),
                'new_task_id' => $newTask['id'] ?? 'unknown',
                'duplicate_of' => $duplicateOf,
                'action' => 'rejected_as_duplicate'
            ]) . "\n",
            FILE_APPEND
        );
    }
}

/**
 * Orphan Task Detector
 * Requirement 38: Find and handle orphan tasks
 */
class OrphanTaskDetector
{
    private const ORPHAN_LOG = __DIR__ . '/../../logs/autonomous/orphans.jsonl';

    /**
     * Detect orphan tasks
     */
    public static function detectOrphans(): array
    {
        $queueFile = __DIR__ . '/../../tasks-queue.json';
        if (!file_exists($queueFile)) {
            return [];
        }

        $queue = json_decode(file_get_contents($queueFile), true) ?? [];
        $tasks = $queue['tasks'] ?? [];

        $orphans = [];

        foreach ($tasks as $task) {
            $taskId = $task['id'] ?? 'unknown';

            // Orphan 1: Running for >1 hour without update
            $startedAt = strtotime($task['started_at'] ?? 'now');
            if ($task['status'] === 'running' && (time() - $startedAt) > 3600) {
                $orphans[] = [
                    'task_id' => $taskId,
                    'type' => 'running_too_long',
                    'duration_seconds' => time() - $startedAt
                ];
            }

            // Orphan 2: Assigned to offline agent
            if ($task['status'] === 'assigned') {
                $agent = $task['assigned_to'][0] ?? '';
                if (!self::isAgentOnline($agent)) {
                    $orphans[] = [
                        'task_id' => $taskId,
                        'type' => 'agent_offline',
                        'agent' => $agent
                    ];
                }
            }

            // Orphan 3: Awaiting review with no reviewer active
            if ($task['status'] === 'awaiting_review') {
                if (!self::isAgentOnline('gpt')) {
                    $orphans[] = [
                        'task_id' => $taskId,
                        'type' => 'reviewer_offline',
                        'reviewer' => 'gpt'
                    ];
                }
            }

            // Orphan 4: Blocked without valid blocker
            if ($task['status'] === 'blocked') {
                $blockedBy = $task['blocked_by'] ?? [];
                foreach ($blockedBy as $blocker) {
                    $blockerTask = self::findTask($blocker, $tasks);
                    if (!$blockerTask || $blockerTask['status'] === 'failed') {
                        $orphans[] = [
                            'task_id' => $taskId,
                            'type' => 'invalid_blocker',
                            'blocker' => $blocker
                        ];
                    }
                }
            }

            // Orphan 5: Completed without evidence
            if ($task['status'] === 'completed') {
                if (!isset($task['evidence']) || empty($task['evidence'])) {
                    $orphans[] = [
                        'task_id' => $taskId,
                        'type' => 'completed_without_evidence'
                    ];
                }
            }

            // Orphan 6: Branch exists but no task
            $branch = 'agent/' . ($task['assigned_to'][0] ?? 'unknown') . '/' . $taskId;
            if (self::branchExists($branch) && $task['status'] !== 'running') {
                $orphans[] = [
                    'task_id' => $taskId,
                    'type' => 'branch_task_mismatch',
                    'branch' => $branch
                ];
            }
        }

        foreach ($orphans as $orphan) {
            self::logOrphan($orphan);
        }

        return $orphans;
    }

    /**
     * Helper: is agent online
     */
    private static function isAgentOnline(string $agent): bool
    {
        $metricsFile = __DIR__ . '/../../logs/agents/' . $agent . '-productivity.json';
        if (!file_exists($metricsFile)) {
            return false;
        }

        $metrics = json_decode(file_get_contents($metricsFile), true) ?? [];
        $lastActivity = $metrics['last_activity'] ?? 0;

        return (time() - $lastActivity) < 600; // Active in last 10 min
    }

    /**
     * Helper: find task by ID
     */
    private static function findTask(string $taskId, array $tasks): ?array
    {
        foreach ($tasks as $task) {
            if ($task['id'] === $taskId) {
                return $task;
            }
        }
        return null;
    }

    /**
     * Helper: branch exists
     */
    private static function branchExists(string $branch): bool
    {
        exec('git branch -a | grep ' . escapeshellarg($branch), $output);
        return count($output) > 0;
    }

    /**
     * Log orphan task
     */
    private static function logOrphan(array $orphan): void
    {
        $dir = dirname(self::ORPHAN_LOG);
        @mkdir($dir, 0755, true);

        file_put_contents(
            self::ORPHAN_LOG,
            json_encode(array_merge(['timestamp' => date('c')], $orphan)) . "\n",
            FILE_APPEND
        );
    }
}
