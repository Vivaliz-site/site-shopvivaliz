<?php
declare(strict_types=1);

require_once __DIR__ . '/event_collector.php';
require_once dirname(__DIR__) . '/bootstrap.php';

const AUTODEV_METRICS_HISTORY_PATH = __DIR__ . '/../data/metrics_history.json';
const AUTODEV_MAX_SNAPSHOTS = 96;

/**
 * Derive a stable session key from IP + User-Agent stored in an event record.
 *
 * @param array $event  A raw event record from get_events().
 * @return string
 */
function autodev_session_key(array $event): string
{
    return (string)($event['session_id'] ?? md5(($event['ip'] ?? '') . '|' . ($event['user_agent'] ?? '')));
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
function autodev_calculate_metrics(int $hours = 24): array
{
    $since  = time() - ($hours * 3600);
    $events = autodev_get_events($since);

    // Group events by session key
    $sessions        = [];   // session_key => [event_type, …]
    $product_views   = [];   // product_id  => count
    $orders          = 0;
    $checkout_starts = 0;

    foreach ($events as $ev) {
        $key  = autodev_session_key($ev);
        $type = $ev['event'] ?? '';

        $sessions[$key][] = $type;

        if ($type === 'order_complete') {
            $orders++;
        }
        if ($type === 'checkout_start') {
            $checkout_starts++;
        }
        if ($type === 'product_view') {
            $product_id = $ev['data']['sku'] ?? $ev['data']['product_id'] ?? 'unknown';
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
        'computed_at'      => date('c'),
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
function autodev_get_funnel(int $hours = 24): array
{
    $since        = time() - ($hours * 3600);
    $funnel_steps = ['page_view', 'product_view', 'add_to_cart', 'checkout_start', 'checkout_submit', 'order_complete'];
    $step_sessions = array_fill_keys($funnel_steps, []);

    $events = autodev_get_events($since);
    foreach ($events as $ev) {
        $type = $ev['event'] ?? '';
        if (!in_array($type, $funnel_steps, true)) {
            continue;
        }
        $key = autodev_session_key($ev);
        $step_sessions[$type][$key] = true;
    }

    return [
        'window_hours'   => $hours,
        'visits'         => count($step_sessions['page_view']),
        'product_views'  => count($step_sessions['product_view']),
        'add_to_cart'    => count($step_sessions['add_to_cart']),
        'checkout_start' => count($step_sessions['checkout_start']),
        'checkout_submit'=> count($step_sessions['checkout_submit']),
        'orders'         => count($step_sessions['order_complete']),
    ];
}

/**
 * Append a metrics snapshot to the history file, keeping at most MAX_SNAPSHOTS.
 *
 * @param array $metrics  Result of calculate_metrics().
 * @return bool
 */
function autodev_save_metrics_snapshot(array $metrics): bool
{
    $history = autodev_load_metrics_history();
    $history[] = $metrics;
    if (count($history) > AUTODEV_MAX_SNAPSHOTS) {
        $history = array_slice($history, -AUTODEV_MAX_SNAPSHOTS);
    }
    return autodev_write_json(AUTODEV_METRICS_HISTORY_PATH, $history);
}

/**
 * Load all stored metrics snapshots.
 *
 * @return array  Array of metric records, oldest first.
 */
function autodev_load_metrics_history(): array
{
    return autodev_read_json(AUTODEV_METRICS_HISTORY_PATH, []);
}
