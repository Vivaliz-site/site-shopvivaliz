<?php
declare(strict_types=1);

/**
 * Incident Management System
 * Requirement 19: Detect, classify, respond to critical failures
 */

class IncidentManager
{
    private const INCIDENT_LOG = __DIR__ . '/../../logs/autonomous/incidents.jsonl';

    const SEV_CRITICAL = 'SEV0'; // Data/sales/payment down
    const SEV_HIGH = 'SEV1';     // Major function broken
    const SEV_MEDIUM = 'SEV2';   // Degraded function
    const SEV_LOW = 'SEV3';      // Minor issue

    /**
     * Detect and classify incident
     */
    public static function detect(string $symptom, array $context = []): ?array
    {
        $severity = self::classifySeverity($symptom, $context);

        if ($severity === null) {
            return null; // Not an incident
        }

        $incident = [
            'incident_id' => uniqid('incident-', true),
            'timestamp' => date('c'),
            'severity' => $severity,
            'symptom' => $symptom,
            'context' => $context,
            'status' => 'detected',
            'evidence' => [],
            'root_cause' => '',
            'actions_taken' => [],
            'rollback_executed' => false
        ];

        self::log($incident);

        // Preserve evidence immediately
        self::preserveEvidence($incident['incident_id'], $context);

        // For critical incidents, halt non-critical work
        if ($severity === self::SEV_CRITICAL) {
            self::haltNonCriticalWork($incident['incident_id']);
        }

        return $incident;
    }

    /**
     * Classify severity based on symptom and context
     */
    private static function classifySeverity(string $symptom, array $context): ?string
    {
        $lowerSymptom = strtolower($symptom);

        // SEV0 (Critical) - Data/Sales/Payment down
        $criticalKeywords = [
            'checkout',
            'payment gateway',
            'database down',
            'unable to save order',
            'transaction failed',
            'data corruption',
            'unable to login',
            'auth down',
            'ddos',
            'cpu maxed',
            'disk full',
            'oom'
        ];

        foreach ($criticalKeywords as $keyword) {
            if (strpos($lowerSymptom, $keyword) !== false) {
                return self::SEV_CRITICAL;
            }
        }

        // SEV1 (High) - Major function broken
        $highKeywords = [
            'test failed',
            'endpoint 500',
            'exception',
            'fatal error',
            'crash',
            'hang',
            'timeout',
            'api down'
        ];

        foreach ($highKeywords as $keyword) {
            if (strpos($lowerSymptom, $keyword) !== false) {
                return self::SEV_HIGH;
            }
        }

        // SEV2 (Medium) - Degraded
        $mediumKeywords = [
            'slow response',
            'partial failure',
            'degraded',
            'intermittent',
            'memory leak'
        ];

        foreach ($mediumKeywords as $keyword) {
            if (strpos($lowerSymptom, $keyword) !== false) {
                return self::SEV_MEDIUM;
            }
        }

        return null; // Not an incident
    }

    /**
     * Apply minimal fix
     */
    public static function applyMinimalFix(string $incidentId, string $fixCommand, string $description): bool
    {
        $incident = self::getIncident($incidentId);
        if (!$incident) {
            return false;
        }

        // Execute minimal fix (usually revert recent change)
        exec($fixCommand, $output, $returnCode);

        if ($returnCode === 0) {
            $incident['status'] = 'fixed';
            $incident['actions_taken'][] = [
                'action' => 'minimal_fix_applied',
                'command' => $fixCommand,
                'description' => $description,
                'result' => 'success',
                'timestamp' => date('c')
            ];

            self::log($incident);
            return true;
        }

        return false;
    }

    /**
     * Execute rollback
     */
    public static function rollback(string $incidentId, string $rollbackCommand): bool
    {
        $incident = self::getIncident($incidentId);
        if (!$incident) {
            return false;
        }

        exec($rollbackCommand, $output, $returnCode);

        if ($returnCode === 0) {
            $incident['rollback_executed'] = true;
            $incident['status'] = 'rolled_back';
            $incident['actions_taken'][] = [
                'action' => 'rollback',
                'command' => $rollbackCommand,
                'result' => 'success',
                'timestamp' => date('c')
            ];

            self::log($incident);
            return true;
        }

        return false;
    }

    /**
     * Record root cause analysis
     */
    public static function recordRootCause(string $incidentId, string $cause, string $prevention): void
    {
        $incident = self::getIncident($incidentId);
        if (!$incident) {
            return;
        }

        $incident['root_cause'] = $cause;
        $incident['prevention_action'] = $prevention;
        $incident['status'] = 'root_cause_identified';

        self::log($incident);
    }

    /**
     * Preserve incident evidence (file backups, logs, state)
     */
    private static function preserveEvidence(string $incidentId, array $context): void
    {
        $evidenceDir = dirname(self::INCIDENT_LOG) . '/incident-' . $incidentId;
        @mkdir($evidenceDir, 0755, true);

        // Save context
        file_put_contents(
            "$evidenceDir/context.json",
            json_encode($context, JSON_PRETTY_PRINT)
        );

        // Save logs if available
        if (isset($context['log_file'])) {
            if (file_exists($context['log_file'])) {
                copy($context['log_file'], "$evidenceDir/error-log.txt");
            }
        }

        // Save system state
        exec('ps aux > ' . escapeshellarg("$evidenceDir/processes.txt") . ' 2>&1');
        exec('free -h > ' . escapeshellarg("$evidenceDir/memory.txt") . ' 2>&1');
        exec('df -h > ' . escapeshellarg("$evidenceDir/disk.txt") . ' 2>&1');
    }

    /**
     * Halt non-critical work (pause agendas)
     */
    private static function haltNonCriticalWork(string $incidentId): void
    {
        // Create pause file
        $pauseFile = dirname(self::INCIDENT_LOG) . '/../.agents-readonly';
        file_put_contents($pauseFile, $incidentId);
    }

    /**
     * Log incident
     */
    private static function log(array $incident): void
    {
        $dir = dirname(self::INCIDENT_LOG);
        @mkdir($dir, 0755, true);

        file_put_contents(
            self::INCIDENT_LOG,
            json_encode($incident) . "\n",
            FILE_APPEND
        );
    }

    /**
     * Get incident by ID
     */
    private static function getIncident(string $incidentId): ?array
    {
        if (!file_exists(self::INCIDENT_LOG)) {
            return null;
        }

        foreach (file(self::INCIDENT_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $incident = json_decode($line, true);
            if ($incident['incident_id'] === $incidentId) {
                return $incident;
            }
        }

        return null;
    }

    /**
     * Get critical incidents (last 7 days)
     */
    public static function getCriticalIncidents(): array
    {
        if (!file_exists(self::INCIDENT_LOG)) {
            return [];
        }

        $week_ago = strtotime('-7 days');
        $critical = [];

        foreach (file(self::INCIDENT_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $incident = json_decode($line, true);
            if ($incident['severity'] === self::SEV_CRITICAL) {
                $incidentTime = strtotime($incident['timestamp']);
                if ($incidentTime > $week_ago) {
                    $critical[] = $incident;
                }
            }
        }

        return $critical;
    }
}
