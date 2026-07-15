<?php
declare(strict_types=1);

/**
 * Human Approval Queue Manager
 * Requirement 33: Manage tasks requiring human approval
 * Requirement 20: Block sensitive changes until approved
 */

class ApprovalQueueManager
{
    private const APPROVAL_QUEUE_FILE = __DIR__ . '/../../logs/autonomous/human-approval-queue.json';
    private const SENSITIVE_ACTIONS = [
        'database_migration',
        'authentication_change',
        'checkout_modification',
        'payment_gateway_change',
        'credential_update',
        'permission_change',
        'firewall_rule',
        'nginx_apache_config',
        'systemd_service',
        'github_action',
        'secret_management',
        'file_deletion',
        'price_change',
        'stock_change',
    ];

    /**
     * Check if action requires approval
     */
    public static function requiresApproval(array $task): bool
    {
        $actions = [
            $task['action'] ?? '',
            $task['type'] ?? '',
            ...($task['affected_systems'] ?? [])
        ];

        foreach ($actions as $action) {
            if (in_array(strtolower($action), self::SENSITIVE_ACTIONS, true)) {
                return true;
            }
        }

        // Check description for dangerous keywords
        $description = strtolower($task['description'] ?? '');
        $dangerousKeywords = ['drop table', 'truncate', 'delete from', 'alter authentication', 'change password', 'update credentials'];
        foreach ($dangerousKeywords as $keyword) {
            if (strpos($description, $keyword) !== false) {
                return true;
            }
        }

        // Check if modifying sensitive files
        $sensitiveFiles = [
            '.env',
            'config/auth.php',
            'config/payment.php',
            'config/database.php',
            'nginx.conf',
            '.github/workflows/',
            'systemd/',
        ];

        $affectedFiles = $task['reserved_files'] ?? [];
        foreach ($affectedFiles as $file) {
            foreach ($sensitiveFiles as $sensitive) {
                if (strpos($file, $sensitive) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Submit task for approval
     */
    public static function submitForApproval(array $task, string $justification): string
    {
        $approvalId = uniqid('approval-', true);

        $approval = [
            'approval_id' => $approvalId,
            'task_id' => $task['id'] ?? 'unknown',
            'task_title' => $task['title'] ?? 'untitled',
            'submitted_at' => date('c'),
            'status' => 'pending',
            'action' => $task['action'] ?? 'unknown',
            'justification' => $justification,
            'risk_level' => $task['risk_level'] ?? 'unknown',
            'impact' => $task['business_value'] ?? 'unknown',
            'affected_files' => $task['reserved_files'] ?? [],
            'commands' => $task['commands'] ?? [],
            'rollback_plan' => $task['rollback_plan'] ?? 'No rollback plan provided',
            'deadline' => $task['deadline'] ?? null,
            'requested_by' => $task['assigned_to'] ?? ['unknown'],
            'approved_by' => null,
            'approval_timestamp' => null,
            'approval_notes' => null,
            'full_task_data' => $task
        ];

        self::appendToQueue($approval);

        return $approvalId;
    }

    /**
     * Get pending approvals
     */
    public static function getPending(): array
    {
        $queue = self::loadQueue();
        return array_filter(
            $queue['approvals'] ?? [],
            fn($a) => $a['status'] === 'pending'
        );
    }

    /**
     * Get approval by ID
     */
    public static function getApproval(string $approvalId): ?array
    {
        $queue = self::loadQueue();
        foreach ($queue['approvals'] ?? [] as $approval) {
            if ($approval['approval_id'] === $approvalId) {
                return $approval;
            }
        }
        return null;
    }

    /**
     * Approve an action
     */
    public static function approve(string $approvalId, string $notes = ''): bool
    {
        $queue = self::loadQueue();
        $found = false;

        foreach ($queue['approvals'] as &$approval) {
            if ($approval['approval_id'] === $approvalId) {
                $approval['status'] = 'approved';
                $approval['approval_timestamp'] = date('c');
                $approval['approval_notes'] = $notes;
                $approval['approved_by'] = getenv('APPROVAL_USER') ?? 'system';
                $found = true;
                break;
            }
        }

        if ($found) {
            self::saveQueue($queue);
            return true;
        }

        return false;
    }

    /**
     * Reject an approval
     */
    public static function reject(string $approvalId, string $reason): bool
    {
        $queue = self::loadQueue();
        $found = false;

        foreach ($queue['approvals'] as &$approval) {
            if ($approval['approval_id'] === $approvalId) {
                $approval['status'] = 'rejected';
                $approval['rejection_reason'] = $reason;
                $approval['rejected_at'] = date('c');
                $approval['rejected_by'] = getenv('APPROVAL_USER') ?? 'system';
                $found = true;
                break;
            }
        }

        if ($found) {
            self::saveQueue($queue);
            return true;
        }

        return false;
    }

    /**
     * Check if task is approved (blocks execution if not)
     */
    public static function isApproved(array $task): bool
    {
        // Check if approval is required
        if (!self::requiresApproval($task)) {
            return true; // Non-sensitive tasks auto-approved
        }

        // Find approval record
        $queue = self::loadQueue();
        foreach ($queue['approvals'] ?? [] as $approval) {
            if ($approval['task_id'] === $task['id'] && $approval['status'] === 'approved') {
                return true;
            }
        }

        return false; // Sensitive task not approved
    }

    /**
     * Block execution of task requiring approval
     */
    public static function blockExecution(array $task): array
    {
        $approvalId = self::submitForApproval(
            $task,
            "Sensitive action requires human review: {$task['action']}"
        );

        return [
            'blocked' => true,
            'reason' => 'approval_required',
            'approval_id' => $approvalId,
            'deadline' => $task['deadline'] ?? null,
            'message' => "Action blocked. Approval required at: approval_id=$approvalId"
        ];
    }

    /**
     * Load entire approval queue
     */
    private static function loadQueue(): array
    {
        if (!file_exists(self::APPROVAL_QUEUE_FILE)) {
            return ['version' => '1.0', 'approvals' => []];
        }

        $data = json_decode(file_get_contents(self::APPROVAL_QUEUE_FILE), true);
        return is_array($data) ? $data : ['version' => '1.0', 'approvals' => []];
    }

    /**
     * Save entire queue
     */
    private static function saveQueue(array $queue): void
    {
        $dir = dirname(self::APPROVAL_QUEUE_FILE);
        @mkdir($dir, 0755, true);

        file_put_contents(
            self::APPROVAL_QUEUE_FILE,
            json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    /**
     * Append single approval
     */
    private static function appendToQueue(array $approval): void
    {
        $queue = self::loadQueue();
        $queue['approvals'][] = $approval;
        self::saveQueue($queue);
    }

    /**
     * Generate approval matrix (for manual review)
     */
    public static function generateApprovalSummary(): string
    {
        $queue = self::loadQueue();
        $approvals = $queue['approvals'] ?? [];

        $pending = array_filter($approvals, fn($a) => $a['status'] === 'pending');
        $approved = array_filter($approvals, fn($a) => $a['status'] === 'approved');
        $rejected = array_filter($approvals, fn($a) => $a['status'] === 'rejected');

        $summary = "APPROVAL QUEUE SUMMARY\n";
        $summary .= "======================\n";
        $summary .= sprintf("Pending: %d | Approved: %d | Rejected: %d\n\n", count($pending), count($approved), count($rejected));

        if (count($pending) > 0) {
            $summary .= "PENDING APPROVALS:\n";
            foreach ($pending as $p) {
                $summary .= sprintf(
                    "- %s [%s] Risk: %s - %s\n",
                    $p['approval_id'],
                    $p['action'],
                    $p['risk_level'],
                    $p['task_title']
                );
            }
        }

        return $summary;
    }
}
