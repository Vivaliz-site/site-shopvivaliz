<?php
declare(strict_types=1);

/**
 * Execution Budget Control
 * Requirement 14: Enforce execution limits per cycle and task
 * Requirement 32: Tool choice logic (use right tool before AI)
 */

class ExecutionBudget
{
    // Per-cycle limits
    private const CYCLE_TIME_LIMIT = 300; // 5 minutes
    private const CYCLE_ATTEMPTS_LIMIT = 10;
    private const CYCLE_FILES_LIMIT = 50;
    private const CYCLE_COMMANDS_LIMIT = 100;

    // Per-task limits
    private const TASK_TIME_LIMIT = 120; // 2 minutes
    private const TASK_ATTEMPTS_LIMIT = 3;
    private const TASK_FILES_LIMIT = 10;
    private const TASK_COMMANDS_LIMIT = 50;

    // Resource limits
    private const CYCLE_CPU_PERCENT = 90;
    private const CYCLE_MEMORY_MB = 1000;
    private const LOG_MAX_SIZE_MB = 100;
    private const DIFF_MAX_SIZE_KB = 500;

    /**
     * Check cycle budget
     */
    public static function checkCycleBudget(): array
    {
        return [
            'time_limit_seconds' => self::CYCLE_TIME_LIMIT,
            'attempts_limit' => self::CYCLE_ATTEMPTS_LIMIT,
            'files_limit' => self::CYCLE_FILES_LIMIT,
            'commands_limit' => self::CYCLE_COMMANDS_LIMIT,
            'cpu_percent_limit' => self::CYCLE_CPU_PERCENT,
            'memory_mb_limit' => self::CYCLE_MEMORY_MB,
        ];
    }

    /**
     * Check if cycle can continue
     */
    public static function canContinueCycle(int $elapsedSeconds, int $attempts, int $filesModified, int $commandsRun): bool
    {
        if ($elapsedSeconds >= self::CYCLE_TIME_LIMIT) return false;
        if ($attempts >= self::CYCLE_ATTEMPTS_LIMIT) return false;
        if ($filesModified >= self::CYCLE_FILES_LIMIT) return false;
        if ($commandsRun >= self::CYCLE_COMMANDS_LIMIT) return false;

        // Check resources
        exec('ps aux | grep autonomous | grep -v grep | awk \'{print $3}\' | head -1', $cpu);
        if ((float)($cpu[0] ?? 0) >= self::CYCLE_CPU_PERCENT) return false;

        exec('ps aux | grep autonomous | grep -v grep | awk \'{print $6}\' | head -1', $mem);
        if ((int)($mem[0] ?? 0) / 1024 >= self::CYCLE_MEMORY_MB) return false;

        return true;
    }

    /**
     * Check task budget
     */
    public static function checkTaskBudget(array $task): array
    {
        $elapsed = time() - strtotime($task['started_at'] ?? 'now');
        $attempts = $task['attempt'] ?? 1;
        $filesModified = count($task['reserved_files'] ?? []);
        $logs = strlen(implode("\n", $task['logs'] ?? []));

        return [
            'time_used_seconds' => $elapsed,
            'time_limit_seconds' => self::TASK_TIME_LIMIT,
            'can_continue' => $elapsed < self::TASK_TIME_LIMIT && $attempts < self::TASK_ATTEMPTS_LIMIT,
            'attempts' => $attempts,
            'attempts_limit' => self::TASK_ATTEMPTS_LIMIT,
            'files_modified' => $filesModified,
            'files_limit' => self::TASK_FILES_LIMIT,
            'log_size_mb' => round($logs / 1024 / 1024, 2),
            'log_limit_mb' => self::LOG_MAX_SIZE_MB,
        ];
    }

    /**
     * Block task if budget exceeded
     */
    public static function blockIfExceeded(array $task): ?array
    {
        $budget = self::checkTaskBudget($task);

        if (!$budget['can_continue']) {
            return [
                'blocked' => true,
                'reason' => 'budget_exceeded',
                'details' => sprintf(
                    "Time: %d/%d sec, Attempts: %d/%d",
                    $budget['time_used_seconds'],
                    $budget['time_limit_seconds'],
                    $budget['attempts'],
                    $budget['attempts_limit']
                ),
                'action_recommended' => 'save_progress_mark_blocked'
            ];
        }

        return null;
    }

    /**
     * Choose right tool before calling AI
     */
    public static function chooseToolBeforeAI(string $problem): array
    {
        $tools = [
            'static_analysis' => ['code style', 'syntax', 'type check'],
            'local_test' => ['unit test', 'regression', 'local validation'],
            'grep' => ['find pattern', 'search', 'locate'],
            'linter' => ['format', 'lint', 'style'],
            'database' => ['query', 'lookup', 'fetch'],
            'internal_api' => ['internal endpoint', 'call service'],
            'documentation' => ['read docs', 'check manual'],
            'deterministic_script' => ['calculation', 'transformation', 'deterministic task'],
        ];

        foreach ($tools as $tool => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($problem, $keyword) !== false) {
                    return [
                        'use_tool' => $tool,
                        'reason' => "Problem mentions: $keyword",
                        'use_ai' => false
                    ];
                }
            }
        }

        return [
            'use_tool' => null,
            'reason' => 'Problem requires reasoning or generation',
            'use_ai' => true
        ];
    }
}
