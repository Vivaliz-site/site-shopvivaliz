<?php
/**
 * AutoDev Metrics CLI
 *
 * Entry point for running metrics operations from the command line or CI.
 *
 * Usage:
 *   php autodev/core/metrics_cli.php snapshot   — calculate + persist a new snapshot, print summary
 *   php autodev/core/metrics_cli.php report     — print full funnel report (last 24 h of snapshots)
 *   php autodev/core/metrics_cli.php check      — check thresholds; exits 1 if action is needed
 */

declare(strict_types=1);

require_once __DIR__ . '/metrics_engine.php';

// ── Threshold definitions ────────────────────────────────────────────────────
// Adjust these to tune when AutoDev considers a metric "actionable".
const THRESHOLDS = [
    'checkout_abandon' => 0.65,   // > 65 % abandon → needs attention
    'cart_abandon'     => 0.75,   // > 75 % cart abandon → needs attention
    'conversion_rate'  => 0.01,   // < 1 % conversion → needs attention
    'bounce_rate'      => 0.60,   // > 60 % bounces → needs attention
];

// ── Helpers ──────────────────────────────────────────────────────────────────

function cli_println(string $msg): void
{
    fwrite(STDOUT, $msg . "\n");
}

function cli_err(string $msg): void
{
    fwrite(STDERR, "[ERROR] $msg\n");
}

/**
 * Format a float as a percentage string.
 */
function pct(float $v): string
{
    return number_format($v * 100, 1) . '%';
}

/**
 * Print a single metrics array as a human-readable table.
 */
function print_metrics(array $m, string $label = ''): void
{
    if ($label !== '') {
        cli_println("\n=== $label ===");
    }

    $timestamp = $m['calculated_at'] ?? $m['timestamp'] ?? 'unknown';
    $hours     = $m['window_hours'] ?? '?';

    cli_println(sprintf("  Snapshot time : %s", $timestamp));
    cli_println(sprintf("  Window        : last %s hours", $hours));
    cli_println(sprintf("  Sessions      : %d", $m['sessions'] ?? 0));
    cli_println(sprintf("  Page views    : %d", $m['page_views'] ?? 0));
    cli_println(sprintf("  Orders        : %d", $m['orders'] ?? 0));
    cli_println('');
    cli_println(sprintf("  Conversion rate   : %s", pct((float)($m['conversion_rate'] ?? 0))));
    cli_println(sprintf("  Checkout abandon  : %s", pct((float)($m['checkout_abandon'] ?? 0))));
    cli_println(sprintf("  Cart abandon      : %s", pct((float)($m['cart_abandon'] ?? 0))));
    cli_println(sprintf("  Bounce rate       : %s", pct((float)($m['bounce_rate'] ?? 0))));
    cli_println(sprintf("  Avg pages/session : %.2f", (float)($m['avg_session_pages'] ?? 0)));
}

// ── Commands ─────────────────────────────────────────────────────────────────

/**
 * snapshot: calculate current metrics, persist to history, print summary.
 */
function cmd_snapshot(): int
{
    cli_println("[AutoDev] Taking metrics snapshot...");

    try {
        $metrics = calculate_metrics();
    } catch (\Throwable $e) {
        cli_err("calculate_metrics() failed: " . $e->getMessage());
        return 1;
    }

    try {
        save_metrics_snapshot($metrics);
    } catch (\Throwable $e) {
        cli_err("save_metrics_snapshot() failed: " . $e->getMessage());
        return 1;
    }

    print_metrics($metrics, 'Snapshot saved');
    cli_println("\n[AutoDev] Snapshot complete.");
    return 0;
}

/**
 * report: print all stored snapshots as a funnel trend report.
 */
function cmd_report(): int
{
    cli_println("[AutoDev] Funnel Report — last 24 h of snapshots");
    cli_println(str_repeat('─', 60));

    $history_path = defined('METRICS_HISTORY_PATH') ? METRICS_HISTORY_PATH : __DIR__ . '/../../data/metrics_history.json';

    if (!file_exists($history_path)) {
        cli_println("No snapshot history found at: $history_path");
        cli_println("Run 'php metrics_cli.php snapshot' first.");
        return 0;
    }

    $raw = file_get_contents($history_path);
    if ($raw === false) {
        cli_err("Cannot read history file.");
        return 1;
    }

    $history = json_decode($raw, true);
    if (!is_array($history) || empty($history)) {
        cli_println("History file is empty.");
        return 0;
    }

    // Print header row
    $header = sprintf(
        "%-22s %10s %10s %10s %10s %8s",
        'Timestamp', 'Conv%', 'CkAbnd%', 'CartAb%', 'Bounce%', 'Orders'
    );
    cli_println($header);
    cli_println(str_repeat('─', 80));

    foreach ($history as $snap) {
        cli_println(sprintf(
            "%-22s %10s %10s %10s %10s %8d",
            substr($snap['calculated_at'] ?? $snap['timestamp'] ?? '', 0, 22),
            pct((float)($snap['conversion_rate'] ?? 0)),
            pct((float)($snap['checkout_abandon'] ?? 0)),
            pct((float)($snap['cart_abandon'] ?? 0)),
            pct((float)($snap['bounce_rate'] ?? 0)),
            (int)($snap['orders'] ?? 0)
        ));
    }

    cli_println(str_repeat('─', 80));
    cli_println(sprintf("Total snapshots: %d", count($history)));
    return 0;
}

/**
 * check: compare latest snapshot against thresholds; exits 1 if any breach.
 */
function cmd_check(): int
{
    cli_println("[AutoDev] Checking metrics thresholds...");

    try {
        $metrics = calculate_metrics(6); // check last 6 hours for freshness
    } catch (\Throwable $e) {
        cli_err("calculate_metrics() failed: " . $e->getMessage());
        return 1; // treat as actionable so CI can alert
    }

    $breaches = [];

    // conversion_rate — LOW is bad
    if (isset($metrics['conversion_rate']) && (float)$metrics['conversion_rate'] < THRESHOLDS['conversion_rate']) {
        $breaches[] = sprintf(
            "conversion_rate %.2f%% is BELOW threshold %.2f%%",
            $metrics['conversion_rate'] * 100,
            THRESHOLDS['conversion_rate'] * 100
        );
    }

    // checkout_abandon — HIGH is bad
    if (isset($metrics['checkout_abandon']) && (float)$metrics['checkout_abandon'] > THRESHOLDS['checkout_abandon']) {
        $breaches[] = sprintf(
            "checkout_abandon %.1f%% exceeds threshold %.1f%%",
            $metrics['checkout_abandon'] * 100,
            THRESHOLDS['checkout_abandon'] * 100
        );
    }

    // cart_abandon — HIGH is bad
    if (isset($metrics['cart_abandon']) && (float)$metrics['cart_abandon'] > THRESHOLDS['cart_abandon']) {
        $breaches[] = sprintf(
            "cart_abandon %.1f%% exceeds threshold %.1f%%",
            $metrics['cart_abandon'] * 100,
            THRESHOLDS['cart_abandon'] * 100
        );
    }

    // bounce_rate — HIGH is bad
    if (isset($metrics['bounce_rate']) && (float)$metrics['bounce_rate'] > THRESHOLDS['bounce_rate']) {
        $breaches[] = sprintf(
            "bounce_rate %.1f%% exceeds threshold %.1f%%",
            $metrics['bounce_rate'] * 100,
            THRESHOLDS['bounce_rate'] * 100
        );
    }

    if (empty($breaches)) {
        cli_println("[AutoDev] All metrics within acceptable range. No action needed.");
        print_metrics($metrics);
        return 0;
    }

    cli_println("[AutoDev] THRESHOLD BREACH — action required:");
    foreach ($breaches as $b) {
        cli_println("  ! $b");
    }
    print_metrics($metrics, 'Current metrics');
    return 1; // non-zero exit signals CI that AutoDev should act
}

// ── Dispatch ─────────────────────────────────────────────────────────────────

$command = $argv[1] ?? '';

switch ($command) {
    case 'snapshot':
        exit(cmd_snapshot());

    case 'report':
        exit(cmd_report());

    case 'check':
        exit(cmd_check());

    default:
        fwrite(STDERR, "Usage: php metrics_cli.php <snapshot|report|check>\n");
        exit(2);
}
