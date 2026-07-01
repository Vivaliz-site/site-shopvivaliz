<?php
declare(strict_types=1);

/**
 * AutoDev Actions — A/B Test Engine
 *
 * Deterministic, lightweight A/B test management without external dependencies.
 *
 * Tests config : autodev/data/ab_tests.json   (JSON object keyed by test name)
 * Results log  : autodev/data/ab_results.log  (JSONL — one event per line)
 *
 * Statistical significance: minimum 100 samples per variant required.
 * Winner is chosen by chi-squared test (p < 0.05) on conversion events.
 */

define('AB_TESTS_PATH',   __DIR__ . '/../../autodev/data/ab_tests.json');
define('AB_RESULTS_LOG',  __DIR__ . '/../../autodev/data/ab_results.log');
define('AB_MIN_SAMPLES',  100);   // per variant
define('AB_CHI_SQ_P05',   3.841); // chi-squared critical value for df=1, p=0.05

// ─── File helpers ─────────────────────────────────────────────────────────────

function _ab_ensure_data_dir(): void
{
    $dir = dirname(AB_TESTS_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

function _ab_load_tests(): array
{
    _ab_ensure_data_dir();
    if (!file_exists(AB_TESTS_PATH)) {
        return [];
    }
    $raw    = file_get_contents(AB_TESTS_PATH);
    $parsed = json_decode($raw, true);
    return is_array($parsed) ? $parsed : [];
}

function _ab_save_tests(array $tests): void
{
    _ab_ensure_data_dir();
    file_put_contents(
        AB_TESTS_PATH,
        json_encode($tests, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

// ─── Core API ─────────────────────────────────────────────────────────────────

/**
 * Create or reset an A/B test.
 *
 * @param string $variant     Identifier for the B variant (e.g. "sticky_price").
 * @param string $name        Unique test name (slug, e.g. "checkout_cta_test").
 * @param string $description Human-readable description of what is being tested.
 * @return array The test configuration that was saved.
 */
function create_ab_test(string $variant, string $name, string $description): array
{
    $tests = _ab_load_tests();

    $config = [
        'name'        => $name,
        'variant'     => $variant,
        'description' => $description,
        'status'      => 'running',
        'created_at'  => date('c'),
        'ended_at'    => null,
        'winner'      => null,
        'min_samples' => AB_MIN_SAMPLES,
        'traffic_split' => '50/50',
    ];

    $tests[$name] = $config;
    _ab_save_tests($tests);

    return $config;
}

/**
 * Deterministically assign a user to variant A or B.
 *
 * The assignment is stable: the same user always gets the same variant
 * for the same test, across requests and deployments.
 *
 * @param string $test_name Registered test name.
 * @param string $user_id   Any stable user identifier (session id, cookie, etc.).
 * @return string "A" or "B".
 */
function get_variant(string $test_name, string $user_id): string
{
    // Use crc32 for speed; the salt makes each test independent.
    $hash = crc32($test_name . ':' . $user_id);
    // crc32 can return negative on some platforms — abs() then mod.
    return (abs($hash) % 2 === 0) ? 'A' : 'B';
}

/**
 * Record a single event (impression, click, conversion, etc.) for a test.
 *
 * @param string $test_name Registered test name.
 * @param string $variant   "A" or "B".
 * @param string $event     Event type, e.g. "impression", "conversion", "purchase".
 * @return void
 */
function record_result(string $test_name, string $variant, string $event): void
{
    _ab_ensure_data_dir();

    $entry = [
        'ts'        => date('c'),
        'unix_ts'   => time(),
        'test'      => $test_name,
        'variant'   => strtoupper($variant),
        'event'     => $event,
    ];

    file_put_contents(
        AB_RESULTS_LOG,
        json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );
}

/**
 * Analyze results and return the winning variant, or null if not yet significant.
 *
 * Uses a chi-squared test on conversion (any event of type "conversion"
 * or "purchase") vs impression counts per variant.
 *
 * Minimum AB_MIN_SAMPLES impressions per variant are required before any
 * result is returned.
 *
 * @param string $test_name Registered test name.
 * @return string|null "A", "B", or null (not significant / not enough data).
 */
function get_winner(string $test_name): ?string
{
    $counts = _ab_read_counts($test_name);

    $aImpr = (int)($counts['A']['impressions'] ?? 0);
    $bImpr = (int)($counts['B']['impressions'] ?? 0);
    $aConv = (int)($counts['A']['conversions'] ?? 0);
    $bConv = (int)($counts['B']['conversions'] ?? 0);

    // Minimum sample gate
    if ($aImpr < AB_MIN_SAMPLES || $bImpr < AB_MIN_SAMPLES) {
        return null;
    }

    $aRate = $aConv / $aImpr;
    $bRate = $bConv / $bImpr;

    // Chi-squared test for proportions
    $total       = $aImpr + $bImpr;
    $totalConv   = $aConv + $bConv;
    $expected_A  = $aImpr * ($totalConv / $total);
    $expected_B  = $bImpr * ($totalConv / $total);
    $expected_A_no = $aImpr - $expected_A;
    $expected_B_no = $bImpr - $expected_B;

    if ($expected_A <= 0 || $expected_B <= 0 ||
        $expected_A_no <= 0 || $expected_B_no <= 0) {
        return null; // degenerate case
    }

    $chiSq = (($aConv    - $expected_A)   ** 2 / $expected_A)
           + (($bConv    - $expected_B)   ** 2 / $expected_B)
           + (($aImpr - $aConv - $expected_A_no) ** 2 / $expected_A_no)
           + (($bImpr - $bConv - $expected_B_no) ** 2 / $expected_B_no);

    if ($chiSq < AB_CHI_SQ_P05) {
        return null; // not statistically significant
    }

    return ($aRate >= $bRate) ? 'A' : 'B';
}

/**
 * Mark a test as complete and record the winner.
 *
 * @param string      $test_name Registered test name.
 * @param string|null $winner    "A", "B", or null for inconclusive.
 * @return array Updated test config, or empty array if test not found.
 */
function end_test(string $test_name, ?string $winner): array
{
    $tests = _ab_load_tests();

    if (!isset($tests[$test_name])) {
        return [];
    }

    $tests[$test_name]['status']   = 'completed';
    $tests[$test_name]['ended_at'] = date('c');
    $tests[$test_name]['winner']   = $winner;

    _ab_save_tests($tests);

    return $tests[$test_name];
}

// ─── Internal helpers ──────────────────────────────────────────────────────────

/**
 * Read and aggregate impression + conversion counts from the results log.
 *
 * @internal
 * @return array<string, array{impressions: int, conversions: int}>
 */
function _ab_read_counts(string $test_name): array
{
    $counts = [
        'A' => ['impressions' => 0, 'conversions' => 0],
        'B' => ['impressions' => 0, 'conversions' => 0],
    ];

    if (!file_exists(AB_RESULTS_LOG)) {
        return $counts;
    }

    $handle = fopen(AB_RESULTS_LOG, 'r');
    if ($handle === false) {
        return $counts;
    }

    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $entry = json_decode($line, true);
        if (!is_array($entry) || ($entry['test'] ?? '') !== $test_name) {
            continue;
        }

        $variant = strtoupper((string)($entry['variant'] ?? ''));
        $event   = strtolower((string)($entry['event']   ?? ''));
        if (!isset($counts[$variant])) {
            continue;
        }

        // Count every logged event as an impression
        $counts[$variant]['impressions']++;

        // Count conversions
        if ($event === 'conversion' || $event === 'purchase') {
            $counts[$variant]['conversions']++;
        }
    }

    fclose($handle);

    return $counts;
}
