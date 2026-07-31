<?php
declare(strict_types=1);

/**
 * Agent Health Monitor
 * Requirement 26: Monitor agent health status
 * Requirement 27: Transparency dashboard data
 * Requirement 47: Infrastructure alerts
 * Requirement 48: Agent SLA monitoring
 */

class HealthMonitor
{
    const HEALTHY = 'healthy';
    const IDLE_VALID = 'idle_valid';
    const IDLE_INVALID = 'idle_invalid';
    const STUCK = 'stuck';
    const FAILING = 'failing';
    const DISABLED = 'disabled';

    private const HEALTH_FILE = __DIR__ . '/../../logs/autonomous/agent-health.json';
    private const SLA_FILE = __DIR__ . '/../../logs/autonomous/sla-tracking.jsonl';

    /**
     * Check agent health
     */
    public static function checkHealth(string $agent): array
    {
        $metrics = self::getAgentMetrics($agent);
        $now = time();

        $heartbeatAge = $now - ($metrics['last_activity'] ?? 0);
        $idleTime = $metrics['idle_time'] ?? 0;
        $tasksCompleted = $metrics['tasks_completed'] ?? 0;
        $currentTask = $metrics['current_task'];

        // Determine status
        $status = self::HEALTHY;

        if ($heartbeatAge > 600) { // >10 min
            $status = self::STUCK;
        } elseif ($heartbeatAge > 300) { // >5 min
            if (!$currentTask) {
                $status = self::IDLE_INVALID;
            } else {
                $status = self::IDLE_VALID;
            }
        }

        if ($metrics['error_count'] ?? 0 > 5) {
            $status = self::FAILING;
        }

        return [
            'agent' => $agent,
            'status' => $status,
            'heartbeat_age_seconds' => $heartbeatAge,
            'idle_time_total' => $idleTime,
            'tasks_completed' => $tasksCompleted,
            'current_task' => $currentTask,
            'error_count' => $metrics['error_count'] ?? 0,
            'cpu_percent' => self::getProcessCPU($agent),
            'memory_mb' => self::getProcessMemory($agent),
            'last_delivery' => $metrics['last_delivery'] ?? null,
            'last_failure' => $metrics['last_failure'] ?? null,
            'timestamp' => date('c')
        ];
    }

    /**
     * Get agent metrics from productivity tracker
     */
    private static function getAgentMetrics(string $agent): array
    {
        $metricsFile = __DIR__ . '/../../logs/agents/' . $agent . '-productivity.json';
        if (!file_exists($metricsFile)) {
            return [];
        }

        return json_decode(file_get_contents($metricsFile), true) ?? [];
    }

    /**
     * Get process CPU usage
     */
    private static function getProcessCPU(string $agent): float
    {
        exec("ps aux | grep $agent | grep -v grep | awk '{print \\$3}'", $output);
        return (float)($output[0] ?? 0);
    }

    /**
     * Get process memory usage
     */
    private static function getProcessMemory(string $agent): float
    {
        exec("ps aux | grep $agent | grep -v grep | awk '{print \\$6}'", $output);
        return (float)($output[0] ?? 0) / 1024; // KB to MB
    }

    /**
     * Check SLA compliance
     */
    public static function checkSLA(string $agent): array
    {
        $metrics = self::getAgentMetrics($agent);
        $now = time();

        $SLAs = [
            'heartbeat' => ['max' => 120, 'actual' => $now - ($metrics['last_activity'] ?? 0)],
            'ready_task_assigned' => ['max' => 300, 'actual' => 0], // Check queue
            'blocked_task_escalated' => ['max' => 600, 'actual' => 0],
            'critical_failure_alerted' => ['max' => 120, 'actual' => 0],
            'gpt_review_started' => ['max' => 600, 'actual' => 0],
        ];

        $violations = [];
        foreach ($SLAs as $name => $sla) {
            if ($sla['actual'] > $sla['max']) {
                $violations[] = [
                    'sla' => $name,
                    'max' => $sla['max'],
                    'actual' => $sla['actual'],
                    'violated' => true
                ];
            }
        }

        self::logSLAStatus($agent, $violations);

        return [
            'agent' => $agent,
            'violations' => $violations,
            'compliant' => count($violations) === 0,
            'timestamp' => date('c')
        ];
    }

    /**
     * Infrastructure monitoring
     */
    public static function checkInfrastructure(): array
    {
        $alerts = [];

        // Disk usage
        exec('df -h / | tail -1 | awk \'{print $5}\'', $diskOutput);
        $diskUsage = (int)str_replace('%', '', $diskOutput[0] ?? '0');
        if ($diskUsage > 80) {
            $alerts[] = ['type' => 'disk', 'usage_percent' => $diskUsage, 'severity' => 'high'];
        }

        // Memory usage
        exec('free | grep Mem | awk \'{print int($3/$2 * 100)}\'', $memOutput);
        $memUsage = (int)($memOutput[0] ?? 0);
        if ($memUsage > 85) {
            $alerts[] = ['type' => 'memory', 'usage_percent' => $memUsage, 'severity' => 'high'];
        }

        // CPU usage
        exec('top -b -n 1 | grep "Cpu(s)" | awk \'{print $2}\' | cut -d\'%\' -f1', $cpuOutput);
        $cpuUsage = (float)($cpuOutput[0] ?? 0);
        if ($cpuUsage > 90) {
            $alerts[] = ['type' => 'cpu', 'usage_percent' => $cpuUsage, 'severity' => 'high'];
        }

        return [
            'disk_usage_percent' => $diskUsage,
            'memory_usage_percent' => $memUsage,
            'cpu_usage_percent' => $cpuUsage,
            'alerts' => $alerts,
            'timestamp' => date('c')
        ];
    }

    /**
     * Log SLA status
     */
    private static function logSLAStatus(string $agent, array $violations): void
    {
        $dir = dirname(self::SLA_FILE);
        @mkdir($dir, 0755, true);

        if (count($violations) > 0) {
            file_put_contents(
                self::SLA_FILE,
                json_encode([
                    'timestamp' => date('c'),
                    'agent' => $agent,
                    'violations' => $violations
                ]) . "\n",
                FILE_APPEND
            );
        }
    }

    /**
     * Generate health summary
     */
    public static function generateSummary(): string
    {
        $agents = ['claude', 'gemini', 'gpt'];
        $summary = "AGENT HEALTH SUMMARY\n";
        $summary .= "====================\n\n";

        foreach ($agents as $agent) {
            $health = self::checkHealth($agent);
            $summary .= sprintf(
                "%s: %s (heartbeat: %ds ago, tasks: %d, errors: %d)\n",
                $agent,
                $health['status'],
                $health['heartbeat_age_seconds'],
                $health['tasks_completed'],
                $health['error_count']
            );
        }

        $infra = self::checkInfrastructure();
        $summary .= "\nINFRASTRUCTURE:\n";
        $summary .= sprintf("Disk: %d%% | Memory: %d%% | CPU: %.1f%%\n",
            $infra['disk_usage_percent'],
            $infra['memory_usage_percent'],
            $infra['cpu_usage_percent']
        );

        return $summary;
    }
}
