<?php
declare(strict_types=1);

/**
 * Task Quality Validator
 * Requirement 15: Ensure all auto-generated tasks meet quality standards
 */

class TaskValidator
{
    /**
     * Validate a task against quality standards
     */
    public static function validate(array $task): array
    {
        $errors = [];
        $warnings = [];

        // Check: Specific title (not vague)
        $title = strtolower(trim($task['title'] ?? ''));
        if (self::isVagueTitle($title)) {
            $errors[] = "Vague title: '$title'. Use specific, actionable titles.";
        }

        // Check: Description is specific
        $description = strtolower(trim($task['description'] ?? ''));
        if (strlen($description) < 20) {
            $errors[] = "Description too short (<20 chars). Provide clear context.";
        }
        if (self::isVagueDescription($description)) {
            $errors[] = "Vague description. Specify exactly what needs to be done.";
        }

        // Check: Has acceptance criteria
        $criteria = $task['acceptance_criteria'] ?? [];
        if (!is_array($criteria) || count($criteria) === 0) {
            $errors[] = "Missing acceptance criteria. Define objective success conditions.";
        } else {
            foreach ($criteria as $criterion) {
                if (strlen(trim($criterion)) < 10) {
                    $errors[] = "Acceptance criterion too vague: '$criterion'";
                }
            }
        }

        // Check: Scope is small
        $scope = strtolower($task['scope'] ?? '');
        if (in_array($scope, ['full', 'entire', 'all', 'everything', 'complete', 'major'], true)) {
            $errors[] = "Scope too large. Break into smaller, focused tasks.";
        }

        // Check: Risk is classified
        $risk = strtolower(trim($task['risk_level'] ?? ''));
        if (!in_array($risk, ['low', 'medium', 'high', 'critical'], true)) {
            $warnings[] = "Risk level not specified. Assumed LOW.";
        }

        // Check: Rollback plan exists (for high-risk tasks)
        if ($risk === 'high' || $risk === 'critical') {
            if (!isset($task['rollback_plan']) || strlen(trim($task['rollback_plan'] ?? '')) < 20) {
                $errors[] = "High-risk task must have rollback plan defined.";
            }
        }

        // Check: Type is valid
        $validTypes = ['bug_fix', 'feature', 'refactor', 'test', 'audit', 'integration', 'documentation', 'security_review'];
        $type = strtolower($task['type'] ?? '');
        if (!in_array($type, $validTypes, true)) {
            $warnings[] = "Unknown task type: '$type'. Should be one of: " . implode(', ', $validTypes);
        }

        // Check: Executable (has clear steps or implementation guide)
        if (!isset($task['implementation_guide']) && !isset($task['steps'])) {
            $warnings[] = "No implementation guide or steps provided. Execution may be ambiguous.";
        }

        // Check: Verifiable (has test criteria or verification steps)
        if (!isset($task['test_criteria']) && !isset($task['verification'])) {
            $warnings[] = "No test or verification criteria. How will completion be validated?";
        }

        // Check: Clear business value
        if (!isset($task['business_value']) || strlen(trim($task['business_value'] ?? '')) < 10) {
            $warnings[] = "Business value not stated. Why is this task important?";
        }

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors,
            'warnings' => $warnings,
            'score' => self::calculateQualityScore($task, $errors, $warnings)
        ];
    }

    /**
     * Detect vague task titles
     */
    private static function isVagueTitle(string $title): bool
    {
        $vaguePhrases = [
            'melhorar',
            'optimize',
            'improve',
            'fix',          // generic 'fix'
            'refactor',     // generic refactor
            'update',       // generic update
            'adjust',
            'change',
            'clean',
            'reorganize',
            'reestruturar',
            'otimizar',
            'arrumar',
            'deixar melhor',
            'fazer funcionar',
        ];

        foreach ($vaguePhrases as $phrase) {
            if (strpos($title, $phrase) !== false) {
                // Check if it's standalone or just too generic
                $words = preg_split('/\s+/', $title);
                if (count($words) <= 3) {
                    return true;
                }
            }
        }

        return strlen($title) < 15;
    }

    /**
     * Detect vague descriptions
     */
    private static function isVagueDescription(string $description): bool
    {
        $vaguePatterns = [
            'system',
            'project',
            'application',
            'website',
            'tudo',
            'geral',
            'everything',
            'all aspects'
        ];

        $words = preg_split('/\s+/', $description);
        $vagueCount = 0;

        foreach ($words as $word) {
            foreach ($vaguePatterns as $pattern) {
                if (strpos($word, $pattern) !== false) {
                    $vagueCount++;
                }
            }
        }

        return ($vagueCount / max(1, count($words))) > 0.3;
    }

    /**
     * Calculate quality score 0-100
     */
    private static function calculateQualityScore(array $task, array $errors, array $warnings): int
    {
        $score = 100;

        // Deductions
        $score -= count($errors) * 25;
        $score -= count($warnings) * 10;

        // Bonuses
        if (!empty($task['acceptance_criteria'])) $score += 5;
        if (!empty($task['implementation_guide'])) $score += 5;
        if (!empty($task['test_criteria'])) $score += 5;
        if (!empty($task['rollback_plan'])) $score += 5;
        if (!empty($task['business_value'])) $score += 5;
        if (!empty($task['risk_level'])) $score += 5;

        return max(0, min(100, $score));
    }

    /**
     * Filter out invalid/low-quality auto-generated tasks before queuing
     */
    public static function shouldAccept(array $task): bool
    {
        $validation = self::validate($task);

        // Must have no errors
        if (!$validation['valid']) {
            return false;
        }

        // Quality score must be >= 60
        if ($validation['score'] < 60) {
            return false;
        }

        // Title must not be too short
        if (strlen(trim($task['title'] ?? '')) < 15) {
            return false;
        }

        return true;
    }

    /**
     * Reject task and record why
     */
    public static function reject(array $task, array $validation): void
    {
        $logDir = dirname(__DIR__, 2) . '/logs/autonomous';
        @mkdir($logDir, 0755, true);

        $rejection = [
            'timestamp' => date('c'),
            'task_id' => $task['id'] ?? 'unknown',
            'task_title' => $task['title'] ?? 'untitled',
            'reason' => 'quality_gate_failed',
            'errors' => $validation['errors'],
            'warnings' => $validation['warnings'],
            'score' => $validation['score'],
            'task_data' => $task
        ];

        file_put_contents(
            "$logDir/rejected-tasks.jsonl",
            json_encode($rejection) . "\n",
            FILE_APPEND
        );
    }
}
