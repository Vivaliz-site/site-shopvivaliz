<?php
declare(strict_types=1);

/**
 * AutoDev Actions — Safe Layout Applier
 *
 * Applies layout changes to layout_config.json with automatic backup,
 * audit logging, metrics-based validation, and one-step rollback.
 *
 * Config file : autodev/data/layout_config.json
 * Backup file : autodev/data/layout_config.backup.json
 * Changes log : autodev/data/changes.log  (JSONL)
 * Metrics log : autodev/core/events.log   (JSONL, written by event_collector)
 */

define('SLA_CONFIG_PATH',  __DIR__ . '/../../autodev/data/layout_config.json');
define('SLA_BACKUP_PATH',  __DIR__ . '/../../autodev/data/layout_config.backup.json');
define('SLA_CHANGES_LOG',  __DIR__ . '/../../autodev/data/changes.log');
define('SLA_EVENTS_LOG',   __DIR__ . '/../../autodev/core/events.log');

// ─── File helpers ─────────────────────────────────────────────────────────────

function _sla_ensure_data_dir(): void
{
    foreach ([dirname(SLA_CONFIG_PATH), dirname(SLA_EVENTS_LOG)] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}

function _sla_read_config(): array
{
    _sla_ensure_data_dir();
    if (!file_exists(SLA_CONFIG_PATH)) {
        return [];
    }
    $raw    = file_get_contents(SLA_CONFIG_PATH);
    $parsed = json_decode($raw, true);
    return is_array($parsed) ? $parsed : [];
}

function _sla_write_config(array $config): void
{
    _sla_ensure_data_dir();
    file_put_contents(
        SLA_CONFIG_PATH,
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function _sla_log(string $action, string $reason, array $meta = []): void
{
    _sla_ensure_data_dir();
    $entry = array_merge([
        'ts'     => date('c'),
        'action' => $action,
        'reason' => $reason,
    ], $meta);
    file_put_contents(
        SLA_CHANGES_LOG,
        json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );
}

// ─── Core API ─────────────────────────────────────────────────────────────────

/**
 * Apply a set of layout changes safely.
 *
 * Steps:
 *   1. Save the current config as a backup.
 *   2. Merge $changes into the config.
 *   3. Write the new config to disk.
 *   4. Append an audit entry to changes.log.
 *
 * @param array  $changes Key-value pairs to merge into layout_config.json.
 * @param string $reason  Human-readable justification for the change.
 * @return array{
 *   success: bool,
 *   backup_saved: bool,
 *   applied: array,
 *   config: array
 * }
 */
function apply(array $changes, string $reason): array
{
    _sla_ensure_data_dir();

    // 1. Backup current config
    $current     = _sla_read_config();
    $backupSaved = false;

    if (!empty($current)) {
        file_put_contents(
            SLA_BACKUP_PATH,
            json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $backupSaved = true;
    }

    // 2 & 3. Apply changes
    $newConfig = array_merge($current, $changes, [
        '_applied_at'     => date('c'),
        '_applied_reason' => $reason,
    ]);
    _sla_write_config($newConfig);

    // 4. Audit log
    _sla_log('apply', $reason, [
        'changes'      => $changes,
        'backup_saved' => $backupSaved,
        'keys_changed' => array_keys($changes),
    ]);

    return [
        'success'      => true,
        'backup_saved' => $backupSaved,
        'applied'      => $changes,
        'config'       => $newConfig,
    ];
}

/**
 * Restore the layout config from the last backup.
 *
 * @param string $reason Human-readable reason for the rollback.
 * @return array{
 *   success: bool,
 *   message: string,
 *   config: array
 * }
 */
function rollback(string $reason): array
{
    if (!file_exists(SLA_BACKUP_PATH)) {
        _sla_log('rollback_failed', $reason, ['error' => 'No backup found']);
        return [
            'success' => false,
            'message' => 'No backup file found — cannot rollback.',
            'config'  => _sla_read_config(),
        ];
    }

    $raw    = file_get_contents(SLA_BACKUP_PATH);
    $backup = json_decode($raw, true);

    if (!is_array($backup)) {
        _sla_log('rollback_failed', $reason, ['error' => 'Backup file is corrupt']);
        return [
            'success' => false,
            'message' => 'Backup file is corrupt — cannot rollback.',
            'config'  => _sla_read_config(),
        ];
    }

    _sla_write_config($backup);

    // Remove backup after restoring so next apply() creates a fresh one
    unlink(SLA_BACKUP_PATH);

    _sla_log('rollback', $reason, ['restored_keys' => array_keys($backup)]);

    return [
        'success' => true,
        'message' => 'Config rolled back to previous state.',
        'config'  => $backup,
    ];
}

/**
 * Read the events log and check whether key metrics improved after the
 * last layout change.
 *
 * Compares conversion rate in the window [now - 2*$hours, now - $hours]
 * (baseline) vs [now - $hours, now] (post-apply).
 *
 * @param int $hours Hours to look back for the post-apply window (default 2).
 * @return array{
 *   baseline_conv: float,
 *   current_conv: float,
 *   improved: bool,
 *   delta: float,
 *   sample_size_ok: bool
 * }
 */
function check_metrics_after_apply(int $hours = 2): array
{
    $now       = time();
    $windowSec = $hours * 3600;

    $baseline = _sla_conv_in_window($now - 2 * $windowSec, $now - $windowSec);
    $current  = _sla_conv_in_window($now - $windowSec, $now);

    $delta       = $current['rate'] - $baseline['rate'];
    $sampleOk    = $baseline['visits'] >= 30 && $current['visits'] >= 30;

    return [
        'baseline_conv'  => round($baseline['rate'], 6),
        'current_conv'   => round($current['rate'], 6),
        'improved'       => $delta >= 0.0,
        'delta'          => round($delta, 6),
        'sample_size_ok' => $sampleOk,
    ];
}

/**
 * Automatically rollback the layout if conversion dropped by more than
 * $threshold (fraction, e.g. 0.1 = 10% relative drop).
 *
 * Only acts if there is a sufficient sample size (>= 30 visits in each window).
 *
 * @param float $threshold Relative conversion drop that triggers rollback.
 * @return array{
 *   action_taken: string,
 *   metrics: array,
 *   rollback_result: array|null
 * }
 */
function auto_rollback_if_worse(float $threshold = 0.1): array
{
    $metrics = check_metrics_after_apply();

    if (!$metrics['sample_size_ok']) {
        return [
            'action_taken'   => 'skipped_insufficient_samples',
            'metrics'        => $metrics,
            'rollback_result'=> null,
        ];
    }

    $baseline = $metrics['baseline_conv'];
    if ($baseline <= 0.0) {
        return [
            'action_taken'   => 'skipped_no_baseline',
            'metrics'        => $metrics,
            'rollback_result'=> null,
        ];
    }

    $relativeDrop = ($baseline - $metrics['current_conv']) / $baseline;

    if ($relativeDrop >= $threshold) {
        $rollbackResult = rollback(
            sprintf(
                'Auto-rollback: conversion dropped %.1f%% (threshold %.1f%%)',
                $relativeDrop * 100,
                $threshold * 100
            )
        );
        return [
            'action_taken'    => 'rolled_back',
            'relative_drop'   => round($relativeDrop, 6),
            'metrics'         => $metrics,
            'rollback_result' => $rollbackResult,
        ];
    }

    return [
        'action_taken'   => 'no_action',
        'relative_drop'  => round($relativeDrop, 6),
        'metrics'        => $metrics,
        'rollback_result'=> null,
    ];
}

// ─── Internal helpers ──────────────────────────────────────────────────────────

/**
 * Compute conversion rate (sales / visits) for events in a time window.
 *
 * @internal
 * @return array{visits: int, sales: int, rate: float}
 */
function _sla_conv_in_window(int $from, int $to): array
{
    $visits = 0;
    $sales  = 0;

    if (!file_exists(SLA_EVENTS_LOG)) {
        return ['visits' => 0, 'sales' => 0, 'rate' => 0.0];
    }

    $handle = fopen(SLA_EVENTS_LOG, 'r');
    if ($handle === false) {
        return ['visits' => 0, 'sales' => 0, 'rate' => 0.0];
    }

    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $ev = json_decode($line, true);
        if (!is_array($ev)) {
            continue;
        }

        $ts   = (int)($ev['ts'] ?? $ev['time'] ?? 0);
        $type = (string)($ev['type'] ?? $ev['event'] ?? '');

        if ($ts < $from || $ts > $to) {
            continue;
        }

        if ($type === 'visit') {
            $visits++;
        } elseif ($type === 'purchase' || $type === 'sale') {
            $sales++;
        }
    }

    fclose($handle);

    $rate = $visits > 0 ? $sales / $visits : 0.0;
    return ['visits' => $visits, 'sales' => $sales, 'rate' => $rate];
}
