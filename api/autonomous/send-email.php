<?php
declare(strict_types=1);

/**
 * Email Sending System for Autonomous Agents
 * Requirement 9 & 25: Real email delivery, executive reports, idle alerts
 */

require_once dirname(__DIR__, 2) . '/scripts/mailer.php';

class EmailSender
{
    public static function send(string $to, string $subject, string $body, string $messageType = 'html'): bool
    {
        $html = $messageType === 'html'
            ? $body
            : '<pre style="white-space:pre-wrap">' . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';

        $result = send_email_with_result($to, $subject, $html);
        if (!($result['success'] ?? false)) {
            self::logError('Central mail provider failed: ' . (string)($result['error'] ?? 'unknown'));
            return false;
        }

        self::logSuccess($to, $subject, 'brevo_api');
        return true;
    }

    /**
     * Send idle agent alert
     */
    public static function sendIdleAlert(string $agent, int $idleSeconds): bool
    {
        $to = getenv('EMAIL_TO') ?: 'fredmourao@gmail.com';
        $subject = "[ALERT] Agent $agent idle for " . floor($idleSeconds / 60) . " minutes";
        $body = <<<HTML
<!DOCTYPE html>
<html>
<body>
<h2>Autonomous Agent Idle Alert</h2>
<p><strong>Agent:</strong> $agent</p>
<p><strong>Idle Time:</strong> $idleSeconds seconds (" . floor($idleSeconds / 60) . " minutes)</p>
<p><strong>Time:</strong> " . date('Y-m-d H:i:s') . " UTC</p>
<p><strong>Action:</strong> Please review queue and agent status.</p>
</body>
</html>
HTML;

        return self::send($to, $subject, $body, 'html');
    }

    /**
     * Send task completion notification
     */
    public static function sendTaskNotification(array $task, string $status, string $details = ''): bool
    {
        $to = getenv('EMAIL_TO') ?: 'fredmourao@gmail.com';
        $taskId = $task['id'] ?? 'UNKNOWN';
        $taskTitle = $task['title'] ?? 'No title';
        $agent = $task['assigned_to'][0] ?? 'unknown';

        $subject = match ($status) {
            'completed' => "[COMPLETE] Task $taskId: $taskTitle",
            'rejected' => "[REJECTED] Task $taskId: $taskTitle",
            'blocked' => "[BLOCKED] Task $taskId: $taskTitle",
            default => "[UPDATE] Task $taskId: $taskTitle"
        };

        $statusBadge = match ($status) {
            'completed' => '✓ APPROVED',
            'rejected' => '✗ REJECTED',
            'blocked' => '⊘ BLOCKED',
            default => $status
        };

        $body = <<<HTML
<!DOCTYPE html>
<html>
<body style="font-family: sans-serif;">
<h2>Task Status Update</h2>
<p><strong>Task ID:</strong> $taskId</p>
<p><strong>Title:</strong> $taskTitle</p>
<p><strong>Agent:</strong> $agent</p>
<p><strong>Status:</strong> <strong style="color: #0066cc;">$statusBadge</strong></p>
<p><strong>Time:</strong> " . date('Y-m-d H:i:s') . " UTC</p>
" . (strlen($details) > 0 ? "<p><strong>Details:</strong></p><pre>$details</pre>" : "") . "
</body>
</html>
HTML;

        return self::send($to, $subject, $body, 'html');
    }

    /**
     * Send executive summary report (Req 25)
     */
    public static function sendExecutiveSummary(array $report): bool
    {
        $to = getenv('EMAIL_TO') ?: 'fredmourao@gmail.com';
        $date = date('Y-m-d H:i:s');

        $completed = count($report['completed_tasks'] ?? []);
        $inProgress = count($report['in_progress_tasks'] ?? []);
        $blocked = count($report['blocked_tasks'] ?? []);
        $errors = count($report['errors'] ?? []);
        $cost = $report['total_cost'] ?? 0;

        $subject = "[REPORT] Autonomous System Summary - $date";

        $body = <<<HTML
<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; line-height: 1.6;">
<h1>ShopVivaliz Autonomous System Report</h1>
<p><strong>Period:</strong> $date UTC</p>

<h2>Summary</h2>
<ul>
<li>Tasks Completed: <strong>$completed</strong></li>
<li>Tasks In Progress: <strong>$inProgress</strong></li>
<li>Tasks Blocked: <strong>$blocked</strong></li>
<li>Critical Errors: <strong>$errors</strong></li>
<li>Estimated Cost: \$<strong>$cost</strong></li>
</ul>

<h2>Agent Productivity</h2>
HTML;

        foreach ($report['agent_metrics'] ?? [] as $agent => $metrics) {
            $body .= "<p><strong>$agent:</strong> {$metrics['tasks_completed']} completed, {$metrics['tests_passed']} tests passed</p>";
        }

        $body .= <<<HTML
<h2>Recent Issues</h2>
HTML;

        if (count($report['errors'] ?? []) > 0) {
            foreach (array_slice($report['errors'] ?? [], 0, 5) as $error) {
                $body .= "<p>- $error</p>";
            }
        } else {
            $body .= "<p>No critical errors reported.</p>";
        }

        $body .= <<<HTML
<h2>Next Steps</h2>
<ul>
<li>Review blocked tasks for intervention</li>
<li>Monitor idle agents</li>
<li>Validate recent completions</li>
</ul>

<p><em>This report was generated automatically by the ShopVivaliz Autonomous System.</em></p>
</body>
</html>
HTML;

        return self::send($to, $subject, $body, 'html');
    }

    /**
     * Log email delivery
     */
    private static function logSuccess(string $to, string $subject, string $method): void
    {
        $logDir = dirname(__DIR__, 2) . '/logs/autonomous';
        @mkdir($logDir, 0755, true);

        $maskedTo = substr($to, 0, 3) . '***' . substr($to, -7);
        $log = [
            'timestamp' => date('c'),
            'status' => 'sent',
            'to' => $maskedTo,
            'subject' => $subject,
            'method' => $method,
            'message_id' => uniqid('shopvivaliz-', true)
        ];

        file_put_contents(
            "$logDir/email-log.jsonl",
            json_encode($log) . "\n",
            FILE_APPEND
        );
    }

    /**
     * Log email errors
     */
    private static function logError(string $error): void
    {
        $logDir = dirname(__DIR__, 2) . '/logs/autonomous';
        @mkdir($logDir, 0755, true);

        $log = [
            'timestamp' => date('c'),
            'status' => 'error',
            'error' => $error
        ];

        file_put_contents(
            "$logDir/email-log.jsonl",
            json_encode($log) . "\n",
            FILE_APPEND
        );
    }
}
