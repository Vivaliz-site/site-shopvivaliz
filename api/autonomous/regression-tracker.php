<?php
declare(strict_types=1);

/**
 * Baseline & Regression Detection
 * Requirement 18: Track baseline tests, detect regressions
 */

class RegressionTracker
{
    private const BASELINE_FILE = __DIR__ . '/../../logs/autonomous/regression-baseline.json';
    private const RESULTS_FILE = __DIR__ . '/../../logs/autonomous/regression-results.jsonl';

    /**
     * Record baseline tests
     */
    public static function recordBaseline(string $testName, array $results): void
    {
        $baseline = self::loadBaseline();

        $baseline['baselines'][$testName] = [
            'timestamp' => date('c'),
            'tests_total' => $results['total'] ?? 0,
            'tests_passed' => $results['passed'] ?? 0,
            'tests_failed' => $results['failed'] ?? 0,
            'pass_rate' => ($results['total'] > 0) ? ($results['passed'] / $results['total']) : 0,
            'metrics' => $results['metrics'] ?? [],
            'command' => $results['command'] ?? '',
            'environment' => $results['environment'] ?? [],
        ];

        self::saveBaseline($baseline);
    }

    /**
     * Run tests and compare to baseline
     */
    public static function detectRegression(string $testName, array $currentResults): array
    {
        $baseline = self::loadBaseline();
        $baselineTest = $baseline['baselines'][$testName] ?? null;

        if (!$baselineTest) {
            return [
                'has_regression' => false,
                'reason' => 'no_baseline',
                'details' => 'No baseline found for this test'
            ];
        }

        $currentPassRate = ($currentResults['total'] > 0)
            ? ($currentResults['passed'] / $currentResults['total'])
            : 0;

        $baselinePassRate = $baselineTest['pass_rate'] ?? 0;
        $regressionThreshold = 0.05; // 5% drop = regression

        if ($currentPassRate < ($baselinePassRate - $regressionThreshold)) {
            $result = [
                'has_regression' => true,
                'test_name' => $testName,
                'baseline_pass_rate' => round($baselinePassRate * 100, 2) . '%',
                'current_pass_rate' => round($currentPassRate * 100, 2) . '%',
                'drop' => round(($baselinePassRate - $currentPassRate) * 100, 2) . '%',
                'baseline_passed' => $baselineTest['tests_passed'] ?? 0,
                'current_passed' => $currentResults['passed'] ?? 0,
                'baseline_failed' => $baselineTest['tests_failed'] ?? 0,
                'current_failed' => $currentResults['failed'] ?? 0,
                'failed_delta' => ($currentResults['failed'] ?? 0) - ($baselineTest['tests_failed'] ?? 0),
            ];

            self::logRegression($result);
            return $result;
        }

        return [
            'has_regression' => false,
            'test_name' => $testName,
            'pass_rate' => round($currentPassRate * 100, 2) . '%',
            'status' => 'healthy'
        ];
    }

    /**
     * Prevent task completion if regression detected
     */
    public static function shouldBlockCompletion(array $testResults): bool
    {
        // Any test regression = block completion
        foreach ($testResults as $testName => $result) {
            if ($result['has_regression'] ?? false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Load baseline data
     */
    private static function loadBaseline(): array
    {
        if (!file_exists(self::BASELINE_FILE)) {
            return ['version' => '1.0', 'baselines' => []];
        }

        $data = json_decode(file_get_contents(self::BASELINE_FILE), true);
        return is_array($data) ? $data : ['version' => '1.0', 'baselines' => []];
    }

    /**
     * Save baseline data
     */
    private static function saveBaseline(array $baseline): void
    {
        $dir = dirname(self::BASELINE_FILE);
        @mkdir($dir, 0755, true);

        file_put_contents(
            self::BASELINE_FILE,
            json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    /**
     * Log regression for analysis
     */
    private static function logRegression(array $result): void
    {
        $dir = dirname(self::RESULTS_FILE);
        @mkdir($dir, 0755, true);

        file_put_contents(
            self::RESULTS_FILE,
            json_encode([
                'timestamp' => date('c'),
                'regression' => $result
            ]) . "\n",
            FILE_APPEND
        );
    }
}
