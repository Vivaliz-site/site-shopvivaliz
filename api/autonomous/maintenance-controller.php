<?php
declare(strict_types=1);

/**
 * Maintenance Mode Controller
 * Requirement 34: Global pause, per-agent pause, readonly mode
 * Requirement 35: Change windows, scheduled maintenance
 */

class MaintenanceController
{
    private const CONTROL_DIR = __DIR__ . '/../../logs/autonomous/.control';
    private const GLOBAL_PAUSE = self::CONTROL_DIR . '/global-pause';
    private const READONLY_MODE = self::CONTROL_DIR . '/readonly';
    private const EMERGENCY_STOP = self::CONTROL_DIR . '/emergency-stop';

    /**
     * Enable global pause (all agents stop)
     */
    public static function pauseAll(string $reason = ''): void
    {
        @mkdir(self::CONTROL_DIR, 0755, true);
        file_put_contents(self::GLOBAL_PAUSE, json_encode([
            'timestamp' => date('c'),
            'reason' => $reason,
            'paused_by' => getenv('USER') ?? 'system'
        ]));
    }

    /**
     * Disable global pause
     */
    public static function resumeAll(): void
    {
        @unlink(self::GLOBAL_PAUSE);
    }

    /**
     * Pause specific agent
     */
    public static function pauseAgent(string $agent, string $reason = ''): void
    {
        @mkdir(self::CONTROL_DIR, 0755, true);
        file_put_contents(self::CONTROL_DIR . '/.pause-' . $agent, json_encode([
            'timestamp' => date('c'),
            'agent' => $agent,
            'reason' => $reason
        ]));
    }

    /**
     * Resume specific agent
     */
    public static function resumeAgent(string $agent): void
    {
        @unlink(self::CONTROL_DIR . '/.pause-' . $agent);
    }

    /**
     * Enable readonly mode (audit only, no changes)
     */
    public static function enableReadonly(string $reason = ''): void
    {
        @mkdir(self::CONTROL_DIR, 0755, true);
        file_put_contents(self::READONLY_MODE, json_encode([
            'timestamp' => date('c'),
            'reason' => $reason,
            'enabled_by' => getenv('USER') ?? 'system'
        ]));
    }

    /**
     * Disable readonly mode
     */
    public static function disableReadonly(): void
    {
        @unlink(self::READONLY_MODE);
    }

    /**
     * Emergency stop (immediate halt)
     */
    public static function emergencyStop(): void
    {
        @mkdir(self::CONTROL_DIR, 0755, true);
        file_put_contents(self::EMERGENCY_STOP, json_encode([
            'timestamp' => date('c'),
            'triggered_by' => getenv('USER') ?? 'system'
        ]));
    }

    /**
     * Check if should execute
     */
    public static function canExecute(string $agent = ''): bool
    {
        // Emergency stop = nothing runs
        if (file_exists(self::EMERGENCY_STOP)) {
            return false;
        }

        // Global pause = nothing runs
        if (file_exists(self::GLOBAL_PAUSE)) {
            return false;
        }

        // Agent-specific pause
        if (!empty($agent) && file_exists(self::CONTROL_DIR . '/.pause-' . $agent)) {
            return false;
        }

        return true;
    }

    /**
     * Check if readonly mode
     */
    public static function isReadonly(): bool
    {
        return file_exists(self::READONLY_MODE);
    }

    /**
     * Define change window
     */
    public static function defineChangeWindow(string $dayOfWeek, string $startTime, string $endTime): void
    {
        $window = [
            'day' => $dayOfWeek,
            'start' => $startTime,
            'end' => $endTime
        ];

        @mkdir(self::CONTROL_DIR, 0755, true);
        file_put_contents(
            self::CONTROL_DIR . '/change-window.json',
            json_encode($window, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Check if within change window
     */
    public static function isWithinWindow(): bool
    {
        $windowFile = self::CONTROL_DIR . '/change-window.json';
        if (!file_exists($windowFile)) {
            return true; // No window = always allowed
        }

        $window = json_decode(file_get_contents($windowFile), true);
        $today = strtolower(date('l'));
        $now = date('H:i');

        if (strtolower($window['day']) !== $today) {
            return false;
        }

        return $now >= $window['start'] && $now <= $window['end'];
    }

    /**
     * Check if change requires window/approval
     */
    public static function needsApprovalForChange(array $task): bool
    {
        $risk = strtolower($task['risk_level'] ?? 'low');

        // High/critical risk needs window + approval
        if (in_array($risk, ['high', 'critical'])) {
            return !self::isWithinWindow();
        }

        return false;
    }
}
