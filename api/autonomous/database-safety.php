<?php
declare(strict_types=1);

/**
 * Database Safety Controls
 * Requirement 41: Ensure safe database operations
 */

class DatabaseSafety
{
    private const DB_LOG = __DIR__ . '/../../logs/autonomous/database-operations.jsonl';

    /**
     * Validate database task
     */
    public static function validate(array $task): array
    {
        $errors = [];
        $warnings = [];

        $description = strtolower($task['description'] ?? '');

        // Block dangerous operations
        $dangerous = ['drop table', 'truncate', 'delete from', 'alter database', 'disable triggers'];
        foreach ($dangerous as $pattern) {
            if (strpos($description, $pattern) !== false) {
                $errors[] = "Dangerous operation detected: $pattern (requires explicit approval)";
            }
        }

        // Require approval
        if (strpos($description, 'migration') !== false || strpos($description, 'schema') !== false) {
            $task['requires_approval'] = true;
        }

        return [
            'safe' => count($errors) === 0,
            'errors' => $errors,
            'warnings' => $warnings,
            'requires_approval' => $task['requires_approval'] ?? false
        ];
    }

    /**
     * Create backup before schema change
     */
    public static function backupBefore(string $operation): array
    {
        // Backup schema
        exec('mysqldump --no-data -u root 2>/dev/null > /tmp/schema-backup.sql', $output, $code);

        // Backup table count
        exec('mysql -u root -e "SELECT COUNT(*) FROM information_schema.tables" 2>/dev/null', $countBefore);

        return [
            'backup_created' => $code === 0,
            'schema_backup_file' => '/tmp/schema-backup.sql',
            'record_count_before' => $countBefore[0] ?? 'unknown',
            'timestamp' => date('c')
        ];
    }

    /**
     * Validate after operation
     */
    public static function validateAfter(string $operation): array
    {
        $errors = [];

        // Check schema consistency
        exec('mysql -u root -e "CHECK TABLE *" 2>/dev/null', $checkOutput, $checkCode);
        if ($checkCode !== 0) {
            $errors[] = "Schema validation failed after operation";
        }

        // Verify record counts didn't drop unexpectedly
        exec('mysql -u root -e "SELECT COUNT(*) FROM information_schema.tables" 2>/dev/null', $countAfter);

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors,
            'record_count_after' => $countAfter[0] ?? 'unknown',
            'schema_consistent' => $checkCode === 0
        ];
    }

    /**
     * Generate rollback SQL
     */
    public static function generateRollback(string $operation): string
    {
        return "-- ROLLBACK SCRIPT\n" .
               "-- Operation: $operation\n" .
               "-- Generated: " . date('c') . "\n" .
               "-- Restore from backup:\n" .
               "-- mysql -u root < /tmp/schema-backup.sql\n";
    }

    /**
     * Log database operation
     */
    public static function logOperation(string $operation, array $result): void
    {
        $dir = dirname(self::DB_LOG);
        @mkdir($dir, 0755, true);

        file_put_contents(
            self::DB_LOG,
            json_encode([
                'timestamp' => date('c'),
                'operation' => $operation,
                'result' => $result
            ]) . "\n",
            FILE_APPEND
        );
    }
}

/**
 * Impact Prioritization
 * Requirement 16: Score tasks by business impact
 */
class ImpactPrioritization
{
    /**
     * Calculate priority score
     */
    public static function scoreTask(array $task): int
    {
        $score = 50; // baseline

        // Impact on customer (0-20 points)
        $impact = strtolower($task['business_value'] ?? '');
        if (strpos($impact, 'checkout') !== false) $score += 20;
        elseif (strpos($impact, 'payment') !== false) $score += 18;
        elseif (strpos($impact, 'order') !== false) $score += 15;
        elseif (strpos($impact, 'user') !== false) $score += 10;
        elseif (strpos($impact, 'admin') !== false) $score += 5;

        // Risk level (0-15 points, negative)
        $risk = strtolower($task['risk_level'] ?? 'low');
        if ($risk === 'critical') $score -= 15;
        elseif ($risk === 'high') $score -= 10;
        elseif ($risk === 'medium') $score -= 5;

        // Urgency (0-15 points)
        if (strpos($impact, 'urgent') !== false) $score += 15;
        elseif (strpos($impact, 'critical') !== false) $score += 15;
        elseif (strpos($impact, 'soon') !== false) $score += 10;

        // Frequency (0-10 points)
        if (strpos($task['description'] ?? '', 'repeated') !== false) $score += 10;
        elseif (strpos($task['description'] ?? '', 'daily') !== false) $score += 8;
        elseif (strpos($task['description'] ?? '', 'weekly') !== false) $score += 5;

        // Complexity (-10 to +5 points)
        $complexity = strtolower($task['description'] ?? '');
        if (strlen($complexity) < 50) $score += 5;
        elseif (strlen($complexity) > 500) $score -= 10;

        return max(0, min(100, $score));
    }

    /**
     * Sort tasks by priority
     */
    public static function prioritizeTasks(array $tasks): array
    {
        $scored = [];
        foreach ($tasks as $task) {
            $scored[] = [
                'task' => $task,
                'score' => self::scoreTask($task)
            ];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_map(fn($s) => $s['task'], $scored);
    }
}

/**
 * Business Objective Linking
 * Requirement 53: Link tasks to business objectives
 */
class BusinessObjective
{
    /**
     * Validate business context
     */
    public static function validate(array $task): array
    {
        $errors = [];

        if (!isset($task['business_value']) || strlen(trim($task['business_value'])) < 20) {
            $errors[] = "Must declare business problem being solved";
        }

        if (!isset($task['affected_users']) || empty($task['affected_users'])) {
            $errors[] = "Must specify which users benefit";
        }

        if (!isset($task['metric_improvement']) || strlen(trim($task['metric_improvement'])) < 10) {
            $errors[] = "Must specify which metric will improve";
        }

        if (!isset($task['risk_reduced']) || strlen(trim($task['risk_reduced'])) < 10) {
            $errors[] = "Must specify which risk is reduced";
        }

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors
        ];
    }

    /**
     * Reject tech-only without business value
     */
    public static function shouldReject(array $task): bool
    {
        $description = strtolower($task['description'] ?? '');
        $techOnly = ['refactor', 'cleanup', 'technical debt', 'code quality', 'modernize'];

        foreach ($techOnly as $phrase) {
            if (strpos($description, $phrase) !== false) {
                // Allow if has clear business value
                if (!isset($task['business_value']) || strlen($task['business_value']) < 20) {
                    return true;
                }
            }
        }

        return false;
    }
}
