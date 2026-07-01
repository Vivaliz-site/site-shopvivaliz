<?php
/**
 * AutoDev Metrics Engine
 * Computes conversion funnel metrics from raw events, persists snapshots.
 */

declare(strict_types=1);

require_once __DIR__ . '/event_collector.php';

define('METRICS_HISTORY_PATH', __DIR__ . '/../../data/metrics_history.json');
define('MAX_SNAPSHOTS', 48);

/**
 * Derive a stable session key from IP + User-Agent stored in an event record.
 *
 * @param array $event  A raw event record from get_events().
 * @return string
 */
function _session_key(array $event): string
{
    return md5(($event['ip'] ?? '') . '|' . ($event['user_agent'] ?? ''));
}

/**
 * Calculate aggregate metrics for the last $hours hours.
 *
 * Returns:
 *   conversion_rate   float  orders / sessions
 *   checkout_abandon  float  (checkout_start - orders) / checkout_start
 *   bounce_rate       float  single-event sessions / sessions
 *   top_products      array  [{product_id, views}, …] top 5
 *   revenue_estimate  float  orders * avg_order_value (placeholder R$ 150.00)
 *   sessions_count    int
 *
 * @param int $hours  Look-back window in hours.
 * @return array
 */
function calculate_metrics(int $hours = 24): array
{
    $since  = time() - ($hours * 3600);
    $events = get_events($since);

    // Group events by session key
    $sessions        = [];   // session_key => [event_type, …]
    $product_views   = [];   // product_id  => count
    $orders          = 0;
    $checkout_starts = 0;

    foreach ($events as $ev) {
        $key  = _session_key($ev);
        $type = $ev['event'] ?? '';

        $sessions[$key][] = $type;

        if ($type === 'order_complete') {
            $orders++;
        }
        if ($type === 'checkout_start') {
            $checkout_starts++;
        }
        if ($type === 'product_view') {
            $product_id = $ev['data']['product_id'] ?? 'unknown';
            $product_views[$product_id] = ($product_views[$product_id] ?? 0) + 1;
        }
    }

    $session_count = count($sessions);

    // Bounce: any session with only a single event (regardless of type)
    $bounced = 0;
    foreach ($sessions as $ev_list) {
        if (count($ev_list) === 1) {
            $bounced++;
        }
    }

    $conversion_rate  = $session_count > 0
        ? round($orders / $session_count, 4)
        : 0.0;
    $checkout_abandon = $checkout_starts > 0
        ? round(($checkout_starts - $orders) / $checkout_starts, 4)
        : 0.0;
    $bounce_rate      = $session_count > 0
        ? round($bounced / $session_count, 4)
        : 0.0;

    // Top 5 products by views
    arsort($product_views);
    $top_products = [];
    $i = 0;
    foreach ($product_views as $pid => $views) {
        $top_products[] = ['product_id' => $pid, 'views' => $views];
        if (++$i >= 5) {
            break;
        }
    }

    // Revenue estimate: each completed order assumed average R$ 150,00
    $avg_order_value  = 150.0;
    $revenue_estimate = round($orders * $avg_order_value, 2);

    return [
        'computed_at'      => date('Y-m-d H:i:s'),
        'window_hours'     => $hours,
        'sessions_count'   => $session_count,
        'orders'           => $orders,
        'checkout_starts'  => $checkout_starts,
        'conversion_rate'  => $conversion_rate,
        'checkout_abandon' => $checkout_abandon,
        'bounce_rate'      => $bounce_rate,
        'top_products'     => $top_products,
        'revenue_estimate' => $revenue_estimate,
    ];
}

/**
 * Return step-by-step funnel counts for the last $hours hours.
 * Each step reports unique sessions that fired that event type.
 *
 * @param int $hours
 * @return array  ['visits' => int, 'product_views' => int, 'add_to_cart' => int,
 *                 'checkout_start' => int, 'orders' => int]
 */
function get_funnel(int $hours = 24): array
{
    $since        = time() - ($hours * 3600);
    $funnel_steps = ['page_view', 'product_view', 'add_to_cart', 'checkout_start', 'order_complete'];
    $step_sessions = array_fill_keys($funnel_steps, []);

    $events = get_events($since);
    foreach ($events as $ev) {
        $type = $ev['event'] ?? '';
        if (!in_array($type, $funnel_steps, true)) {
            continue;
        }
        $key = _session_key($ev);
        $step_sessions[$type][$key] = true;
    }

    return [
        'window_hours'   => $hours,
        'visits'         => count($step_sessions['page_view']),
        'product_views'  => count($step_sessions['product_view']),
        'add_to_cart'    => count($step_sessions['add_to_cart']),
        'checkout_start' => count($step_sessions['checkout_start']),
        'orders'         => count($step_sessions['order_complete']),
    ];
}

/**
 * Append a metrics snapshot to the history file, keeping at most MAX_SNAPSHOTS.
 *
 * @param array $metrics  Result of calculate_metrics().
 * @return bool
 */
function save_metrics_snapshot(array $metrics): bool
{
    $dir = dirname(METRICS_HISTORY_PATH);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('[AutoDev] Cannot create data directory for metrics history.');
            return false;
        }
    }

    $mode = file_exists(METRICS_HISTORY_PATH) ? 'r+' : 'w+';
    $fh   = fopen(METRICS_HISTORY_PATH, $mode);
    if ($fh === false) {
        error_log('[AutoDev] Cannot open metrics_history.json.');
        return false;
    }

    flock($fh, LOCK_EX);

    $content = '';
    while (!feof($fh)) {
        $content .= fread($fh, 8192);
    }

    $history = [];
    if ($content !== '') {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $history = $decoded;
        }
    }

    $history[] = $metrics;

    // Trim to MAX_SNAPSHOTS most recent entries
    if (count($history) > MAX_SNAPSHOTS) {
        $history = array_slice($history, -MAX_SNAPSHOTS);
    }

    $json = json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    rewind($fh);
    ftruncate($fh, 0);
    fwrite($fh, $json . "\n");

    flock($fh, LOCK_UN);
    fclose($fh);

    return true;
}

/**
 * Load all stored metrics snapshots.
 *
 * @return array  Array of metric records, oldest first.
 */
function load_metrics_history(): array
{
    if (!file_exists(METRICS_HISTORY_PATH)) {
        return [];
    }

    $fh = fopen(METRICS_HISTORY_PATH, 'r');
    if ($fh === false) {
        error_log('[AutoDev] Cannot open metrics_history.json for reading.');
        return [];
    }

    flock($fh, LOCK_SH);
    $content = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    if ($content === '' || $content === false) {
        return [];
    }

    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}
