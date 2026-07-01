<?php
declare(strict_types=1);

/**
 * AutoDev Evolution — UX Optimizer
 *
 * Applies evidence-based UX configurations to layout_config.json and
 * maintains an audit log of every change made.
 *
 * Config file : autodev/data/layout_config.json
 * Change log  : autodev/data/ux_changes.log  (JSONL)
 */

define('UX_CONFIG_PATH',   __DIR__ . '/../../autodev/data/layout_config.json');
define('UX_CHANGES_LOG',   __DIR__ . '/../../autodev/data/ux_changes.log');

// ─── Default configuration ─────────────────────────────────────────────────────

function _ux_defaults(): array
{
    return [
        // Checkout
        'checkout_simplified'       => false,
        'show_trust_badges'         => false,
        'sticky_cta'                => false,
        // Product page
        'show_urgency'              => false,
        'show_social_proof'         => false,
        'image_zoom'                => false,
        'above_fold_price'          => false,
        // Home
        'hero_banner_variant'       => 'default',
        'show_bestsellers_first'    => false,
        'popup_delay_seconds'       => 5,
        // Meta
        '_history'                  => [],
    ];
}

// ─── Config I/O ───────────────────────────────────────────────────────────────

/**
 * Return current layout_config.json contents merged with defaults.
 */
function get_current_config(): array
{
    _ux_ensure_data_dir();

    if (!file_exists(UX_CONFIG_PATH)) {
        return _ux_defaults();
    }

    $raw = file_get_contents(UX_CONFIG_PATH);
    $parsed = json_decode($raw, true);

    if (!is_array($parsed)) {
        return _ux_defaults();
    }

    return array_merge(_ux_defaults(), $parsed);
}

function _ux_save_config(array $config): void
{
    _ux_ensure_data_dir();
    file_put_contents(
        UX_CONFIG_PATH,
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function _ux_ensure_data_dir(): void
{
    $dir = dirname(UX_CONFIG_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ─── Audit logging ─────────────────────────────────────────────────────────────

function _ux_log(string $change, string $reason, array $details = []): void
{
    _ux_ensure_data_dir();
    $entry = array_merge([
        'ts'     => date('c'),
        'change' => $change,
        'reason' => $reason,
    ], $details);
    file_put_contents(
        UX_CHANGES_LOG,
        json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );
}

// ─── Single-key update ────────────────────────────────────────────────────────

/**
 * Update a single config key and record the change in history.
 *
 * @param string $key   Top-level config key (must not start with '_').
 * @param mixed  $value New value.
 * @return array Updated config.
 */
function apply_config(string $key, mixed $value): array
{
    if (str_starts_with($key, '_')) {
        throw new InvalidArgumentException("Cannot mutate internal config key '{$key}'.");
    }

    $config    = get_current_config();
    $old       = $config[$key] ?? null;
    $config[$key] = $value;

    // Append to history (keep last 100 entries)
    $historyEntry = [
        'ts'    => date('c'),
        'key'   => $key,
        'old'   => $old,
        'new'   => $value,
    ];
    $history   = $config['_history'] ?? [];
    $history[] = $historyEntry;
    $config['_history'] = array_slice($history, -100);

    _ux_save_config($config);
    _ux_log("apply_config:{$key}", "Manual apply via apply_config()", [
        'key' => $key, 'old' => $old, 'new' => $value,
    ]);

    return $config;
}

// ─── Preset optimizations ─────────────────────────────────────────────────────

/**
 * Apply checkout-focused UX improvements.
 *
 * Evidence: simplified single-page checkouts reduce abandon by 12–20%.
 * Trust badges near the CTA lift completions by ~8%.
 *
 * @return array Updated config.
 */
function optimize_checkout(): array
{
    $config = get_current_config();
    $changes = [
        'checkout_simplified' => true,
        'show_trust_badges'   => true,
        'sticky_cta'          => true,
    ];

    foreach ($changes as $k => $v) {
        $old = $config[$k] ?? null;
        $config[$k] = $v;
        $history = $config['_history'] ?? [];
        $history[] = ['ts' => date('c'), 'key' => $k, 'old' => $old, 'new' => $v];
        $config['_history'] = array_slice($history, -100);
    }

    _ux_save_config($config);
    _ux_log(
        'optimize_checkout',
        'Checkout UX preset: simplified flow + trust badges + sticky CTA',
        ['changes' => $changes]
    );

    return $config;
}

/**
 * Apply product-page UX improvements.
 *
 * Evidence: urgency cues lift conversion 8–15%; social proof adds 4–7%;
 * above-fold pricing reduces scroll-required friction.
 *
 * @return array Updated config.
 */
function optimize_product_page(): array
{
    $config = get_current_config();
    $changes = [
        'show_urgency'      => true,
        'show_social_proof' => true,
        'image_zoom'        => true,
        'above_fold_price'  => true,
    ];

    foreach ($changes as $k => $v) {
        $old = $config[$k] ?? null;
        $config[$k] = $v;
        $history = $config['_history'] ?? [];
        $history[] = ['ts' => date('c'), 'key' => $k, 'old' => $old, 'new' => $v];
        $config['_history'] = array_slice($history, -100);
    }

    _ux_save_config($config);
    _ux_log(
        'optimize_product_page',
        'Product page UX preset: urgency + social proof + zoom + above-fold price',
        ['changes' => $changes]
    );

    return $config;
}

/**
 * Apply home-page UX improvements.
 *
 * Evidence: conversion-focused hero reduces bounce ~6%; bestseller ordering
 * improves session product views by ~10%; delayed popup cuts early exits.
 *
 * @return array Updated config.
 */
function optimize_home(): array
{
    $config = get_current_config();
    $changes = [
        'hero_banner_variant'    => 'conversion',
        'show_bestsellers_first' => true,
        'popup_delay_seconds'    => 30,
    ];

    foreach ($changes as $k => $v) {
        $old = $config[$k] ?? null;
        $config[$k] = $v;
        $history = $config['_history'] ?? [];
        $history[] = ['ts' => date('c'), 'key' => $k, 'old' => $old, 'new' => $v];
        $config['_history'] = array_slice($history, -100);
    }

    _ux_save_config($config);
    _ux_log(
        'optimize_home',
        'Home UX preset: conversion hero banner + bestsellers first + delayed popup',
        ['changes' => $changes]
    );

    return $config;
}
