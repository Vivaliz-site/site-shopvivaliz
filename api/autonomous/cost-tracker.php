<?php
declare(strict_types=1);

/**
 * AI Model Usage & Cost Tracker
 * Requirement 31: Track model usage, tokens, cost per agent
 */

class CostTracker
{
    private const COST_LOG_FILE = __DIR__ . '/../../logs/autonomous/cost-tracking.jsonl';
    private const BUDGET_FILE = __DIR__ . '/../../logs/autonomous/cost-budget.json';

    // Token costs per model (USD)
    private const MODEL_COSTS = [
        'claude-3-sonnet' => ['input' => 0.003 / 1000, 'output' => 0.015 / 1000],
        'claude-3-opus' => ['input' => 0.015 / 1000, 'output' => 0.075 / 1000],
        'gpt-4' => ['input' => 0.03 / 1000, 'output' => 0.06 / 1000],
        'gpt-3.5-turbo' => ['input' => 0.0005 / 1000, 'output' => 0.0015 / 1000],
        'gemini-pro' => ['input' => 0.0005 / 1000, 'output' => 0.0015 / 1000],
    ];

    private const MONTHLY_BUDGET = 100.00; // USD
    private const DAILY_BUDGET = 5.00;
    private const HOURLY_BUDGET = 0.25;
    private const TASK_BUDGET = 2.00;

    /**
     * Record API call cost
     */
    public static function recordCall(
        string $agent,
        string $model,
        int $inputTokens,
        int $outputTokens,
        string $taskId = '',
        float $elapsedSeconds = 0.0
    ): float {
        $costs = self::MODEL_COSTS[$model] ?? self::MODEL_COSTS['claude-3-sonnet'];
        $inputCost = $inputTokens * $costs['input'];
        $outputCost = $outputTokens * $costs['output'];
        $totalCost = $inputCost + $outputCost;

        $record = [
            'timestamp' => date('c'),
            'agent' => $agent,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'input_cost' => round($inputCost, 4),
            'output_cost' => round($outputCost, 4),
            'total_cost' => round($totalCost, 4),
            'task_id' => $taskId,
            'elapsed_seconds' => $elapsedSeconds,
        ];

        file_put_contents(
            self::COST_LOG_FILE,
            json_encode($record) . "\n",
            FILE_APPEND
        );

        return $totalCost;
    }

    /**
     * Get costs for agent within period
     */
    public static function getAgentCosts(string $agent, string $period = 'daily'): array
    {
        $records = self::readCostLog();
        $filtered = array_filter($records, fn($r) => $r['agent'] === $agent);

        if (empty($filtered)) {
            return [
                'agent' => $agent,
                'calls' => 0,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'total_cost' => 0.0
            ];
        }

        return [
            'agent' => $agent,
            'calls' => count($filtered),
            'input_tokens' => array_sum(array_column($filtered, 'input_tokens')),
            'output_tokens' => array_sum(array_column($filtered, 'output_tokens')),
            'total_tokens' => array_sum(array_column($filtered, 'total_tokens')),
            'total_cost' => round(array_sum(array_column($filtered, 'total_cost')), 4)
        ];
    }

    /**
     * Get total costs for all agents
     */
    public static function getTotalCosts(): array
    {
        $records = self::readCostLog();

        if (empty($records)) {
            return [
                'total_calls' => 0,
                'total_tokens' => 0,
                'total_cost' => 0.0,
                'by_agent' => [],
                'by_model' => []
            ];
        }

        $byAgent = [];
        $byModel = [];

        foreach ($records as $record) {
            $agent = $record['agent'];
            $model = $record['model'];

            if (!isset($byAgent[$agent])) {
                $byAgent[$agent] = ['calls' => 0, 'cost' => 0.0];
            }
            if (!isset($byModel[$model])) {
                $byModel[$model] = ['calls' => 0, 'cost' => 0.0];
            }

            $byAgent[$agent]['calls']++;
            $byAgent[$agent]['cost'] += $record['total_cost'];
            $byModel[$model]['calls']++;
            $byModel[$model]['cost'] += $record['total_cost'];
        }

        return [
            'total_calls' => count($records),
            'total_tokens' => array_sum(array_column($records, 'total_tokens')),
            'total_cost' => round(array_sum(array_column($records, 'total_cost')), 4),
            'by_agent' => $byAgent,
            'by_model' => $byModel
        ];
    }

    /**
     * Check if within budget
     */
    public static function checkBudget(string $agent = ''): array
    {
        $costs = self::getTotalCosts();
        $totalCost = $costs['total_cost'];

        $today = date('Y-m-d');
        $currentHour = date('Y-m-d H:00:00');

        $dailyRecords = array_filter(
            self::readCostLog(),
            fn($r) => strpos($r['timestamp'], $today) === 0
        );
        $dailyCost = round(array_sum(array_column($dailyRecords, 'total_cost')), 4);

        $hourlyRecords = array_filter(
            self::readCostLog(),
            fn($r) => strpos($r['timestamp'], $currentHour) === 0
        );
        $hourlyCost = round(array_sum(array_column($hourlyRecords, 'total_cost')), 4);

        $status = [
            'monthly' => [
                'used' => $totalCost,
                'limit' => self::MONTHLY_BUDGET,
                'remaining' => max(0, self::MONTHLY_BUDGET - $totalCost),
                'exceeded' => $totalCost > self::MONTHLY_BUDGET,
                'percent' => round(($totalCost / self::MONTHLY_BUDGET) * 100, 1)
            ],
            'daily' => [
                'used' => $dailyCost,
                'limit' => self::DAILY_BUDGET,
                'remaining' => max(0, self::DAILY_BUDGET - $dailyCost),
                'exceeded' => $dailyCost > self::DAILY_BUDGET,
                'percent' => round(($dailyCost / self::DAILY_BUDGET) * 100, 1)
            ],
            'hourly' => [
                'used' => $hourlyCost,
                'limit' => self::HOURLY_BUDGET,
                'remaining' => max(0, self::HOURLY_BUDGET - $hourlyCost),
                'exceeded' => $hourlyCost > self::HOURLY_BUDGET,
                'percent' => round(($hourlyCost / self::HOURLY_BUDGET) * 100, 1)
            ]
        ];

        return $status;
    }

    /**
     * Should continue executing based on budget
     */
    public static function shouldContinue(string $agent): bool
    {
        $budget = self::checkBudget($agent);

        // If any critical budget exceeded, stop
        if ($budget['hourly']['exceeded'] || $budget['daily']['exceeded']) {
            return false;
        }

        // If monthly budget >90%, slow down
        if ($budget['monthly']['percent'] > 90) {
            return false;
        }

        return true;
    }

    /**
     * Read cost log
     */
    private static function readCostLog(): array
    {
        if (!file_exists(self::COST_LOG_FILE)) {
            return [];
        }

        $records = [];
        foreach (file(self::COST_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $record = json_decode($line, true);
            if (is_array($record)) {
                $records[] = $record;
            }
        }

        return $records;
    }
}
