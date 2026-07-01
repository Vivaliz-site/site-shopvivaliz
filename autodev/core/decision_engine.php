<?php
/**
 * AutoDev Decision Engine
 * Orchestrates metric loading, decision-making, cooldown enforcement,
 * action dispatch, and decision logging.
 *
 * CLI usage:
 *   php decision_engine.php
 */

declare(strict_types=1);

require_once __DIR__ . '/event_collector.php';
require_once __DIR__ . '/metrics_engine.php';

define('DECISIONS_LOG_PATH', __DIR__ . '/../../data/decisions.log');
define('ACTION_COOLDOWN_SECONDS', 7200); // 2 hours

// Decision thresholds
define('THRESHOLD_CHECKOUT_ABANDON', 0.6);
define('THRESHOLD_CONVERSION_RATE',  0.02);
define('THRESHOLD_BOUNCE_RATE',      0.7);

/**
 * Apply decision logic against the provided metrics.
 *
 * Priority order:
 *   1. checkout_abandon > 0.6  → optimize_checkout
 *   2. conversion_rate  < 0.02 → optimize_product_page
 *   3. bounce_rate      > 0.7  → optimize_home
 *   4. (else)                  → no_action
 *
 * @param array $metrics  Result of calculate_metrics().
 * @return string         Action identifier.
 */
function decide(array $metrics): string
{
    if (($metrics['checkout_abandon'] ?? 0.0) > THRESHOLD_CHECKOUT_ABANDON) {
        return 'optimize_checkout';
    }

    if (($metrics['conversion_rate'] ?? 1.0) < THRESHOLD_CONVERSION_RATE) {
        return 'optimize_product_page';
    }

    if (($metrics['bounce_rate'] ?? 0.0) > THRESHOLD_BOUNCE_RATE) {
        return 'optimize_home';
    }

    return 'no_action';
}

/**
 * Append a decision record to the decisions log.
 *
 * @param string $action   The decided action.
 * @param string $reason   Human-readable reason for the decision.
 * @param array  $metrics  Metrics snapshot at decision time.
 * @return bool
 */
function _log_decision(string $action, string $reason, array $metrics): bool
{
    $dir = dirname(DECISIONS_LOG_PATH);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('[AutoDev] Cannot create data directory for decisions log.');
            return false;
        }
    }

    $record = [
        'timestamp'       => time(),
        'datetime'        => date('Y-m-d H:i:s'),
        'action'          => $action,
        'reason'          => $reason,
        'metrics_snapshot' => $metrics,
    ];

    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

    $fh = fopen(DECISIONS_LOG_PATH, 'a');
    if ($fh === false) {
        error_log('[AutoDev] Cannot open decisions.log for writing.');
        return false;
    }

    flock($fh, LOCK_EX);
    $written = fwrite($fh, $line);
    flock($fh, LOCK_UN);
    fclose($fh);

    return $written !== false;
}

/**
 * Check whether the given action was already executed within the cooldown window.
 *
 * @param string $action              The action to check.
 * @param int    $cooldown_seconds    Seconds to enforce between same-action runs.
 * @return bool  True if still in cooldown (should skip), false if safe to run.
 */
function _is_in_cooldown(string $action, int $cooldown_seconds = ACTION_COOLDOWN_SECONDS): bool
{
    if (!file_exists(DECISIONS_LOG_PATH)) {
        return false;
    }

    $fh = fopen(DECISIONS_LOG_PATH, 'r');
    if ($fh === false) {
        return false;
    }

    flock($fh, LOCK_SH);
    $lines = [];
    while (($line = fgets($fh)) !== false) {
        $lines[] = trim($line);
    }
    flock($fh, LOCK_UN);
    fclose($fh);

    $cutoff = time() - $cooldown_seconds;

    foreach (array_reverse($lines) as $line) {
        if ($line === '') {
            continue;
        }
        $record = json_decode($line, true);
        if (!is_array($record)) {
            continue;
        }
        // Only look at entries within the cooldown window
        if (($record['timestamp'] ?? 0) < $cutoff) {
            break; // Lines are in chronological order; nothing older matters
        }
        if (($record['action'] ?? '') === $action) {
            return true;
        }
    }

    return false;
}

/**
 * Dispatch the action to the relevant evolution/action module.
 *
 * Each case should require_once the appropriate action file from
 * __DIR__ . '/../../autodev/actions/<action>.php' once those modules exist.
 * For now, a structured log entry and a hook-ready stub is provided.
 *
 * @param string $action   Action identifier returned by decide().
 * @param array  $metrics  Current metrics passed to the action handler.
 * @return void
 */
function execute_action(string $action, array $metrics): void
{
    $action_file = __DIR__ . '/../../autodev/actions/' . $action . '.php';

    if ($action === 'no_action') {
        echo "[AutoDev] No action required at this time.\n";
        return;
    }

    if (file_exists($action_file)) {
        require_once $action_file;
        $fn = 'action_' . $action;
        if (function_exists($fn)) {
            $fn($metrics);
            return;
        }
    }

    // Fallback: log that the action module was not found but the decision was made
    echo "[AutoDev] Action '{$action}' dispatched (module not yet implemented at {$action_file}).\n";
}

/**
 * Main orchestration loop.
 *
 * 1. Compute metrics.
 * 2. Decide on an action.
 * 3. Enforce cooldown.
 * 4. Log the decision.
 * 5. Execute the action.
 * 6. Persist a metrics snapshot.
 *
 * @return void
 */
function run(): void
{
    echo "[AutoDev] Starting decision engine at " . date('Y-m-d H:i:s') . "\n";

    // Step 1 – Metrics
    $metrics = calculate_metrics(24);
    echo "[AutoDev] Metrics: conversion={$metrics['conversion_rate']} abandon={$metrics['checkout_abandon']} bounce={$metrics['bounce_rate']} sessions={$metrics['sessions_count']}\n";

    // Step 2 – Decision
    $action = decide($metrics);
    echo "[AutoDev] Decided action: {$action}\n";

    // Step 3 – Cooldown check (skip execution but still log if in cooldown)
    if ($action !== 'no_action' && _is_in_cooldown($action)) {
        $reason = "Skipped: action '{$action}' is within the " . (ACTION_COOLDOWN_SECONDS / 3600) . "h cooldown window.";
        echo "[AutoDev] {$reason}\n";
        _log_decision($action . '_skipped', $reason, $metrics);
        return;
    }

    // Step 4 – Build reason string
    $reason = _build_reason($action, $metrics);

    // Step 5 – Log decision
    _log_decision($action, $reason, $metrics);
    echo "[AutoDev] Decision logged: {$reason}\n";

    // Step 6 – Execute
    execute_action($action, $metrics);

    // Step 7 – Persist metrics snapshot
    save_metrics_snapshot($metrics);
    echo "[AutoDev] Metrics snapshot saved.\n";
}

/**
 * Build a human-readable reason string explaining the decision.
 *
 * @param string $action
 * @param array  $metrics
 * @return string
 */
function _build_reason(string $action, array $metrics): string
{
    switch ($action) {
        case 'optimize_checkout':
            return sprintf(
                'Checkout abandonment rate %.1f%% exceeds threshold of %.0f%%.',
                ($metrics['checkout_abandon'] ?? 0) * 100,
                THRESHOLD_CHECKOUT_ABANDON * 100
            );
        case 'optimize_product_page':
            return sprintf(
                'Conversion rate %.2f%% is below minimum threshold of %.0f%%.',
                ($metrics['conversion_rate'] ?? 0) * 100,
                THRESHOLD_CONVERSION_RATE * 100
            );
        case 'optimize_home':
            return sprintf(
                'Bounce rate %.1f%% exceeds threshold of %.0f%%.',
                ($metrics['bounce_rate'] ?? 0) * 100,
                THRESHOLD_BOUNCE_RATE * 100
            );
        default:
            return 'All metrics are within acceptable ranges. No action needed.';
    }
}

// ── CLI entry point ────────────────────────────────────────────────────────────
if (php_sapi_name() === 'cli') {
    run();
}
