<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/metrics_engine.php';

/**
 * AutoDev Evolution — Conversion Analyzer
 *
 * Analyzes funnel events to surface conversion rate, drop-off points,
 * best/worst hours, and underperforming products.
 *
 * All functions are pure (no side effects) except generate_report(),
 * which reads from the shared events log when $hours is provided.
 */

// ─── Core analysis ────────────────────────────────────────────────────────────

/**
 * Compute top-level conversion metrics from a raw data snapshot.
 *
 * @param array $data Keys: visits, sales, checkout_start, orders,
 *                    revenue (float), session_product_views (int)
 * @return array {
 *   conversion_rate: float,      // sales / visits
 *   checkout_abandon: float,     // (checkout_start - orders) / checkout_start
 *   revenue_per_visit: float,    // revenue / visits
 *   avg_session_products: float  // session_product_views / visits
 * }
 */
function analyze_conversion(array $data): array
{
    $visits          = max(1, (int)($data['visits'] ?? 0));
    $sales           = max(0, (int)($data['sales'] ?? 0));
    $checkoutStart   = max(0, (int)($data['checkout_start'] ?? 0));
    $orders          = max(0, (int)($data['orders'] ?? 0));
    $revenue         = max(0.0, (float)($data['revenue'] ?? 0.0));
    $sessionProducts = max(0, (int)($data['session_product_views'] ?? 0));

    $checkoutAbandon = $checkoutStart > 0
        ? max(0.0, min(1.0, ($checkoutStart - $orders) / $checkoutStart))
        : 0.0;

    return [
        'conversion_rate'      => round($sales / $visits, 6),
        'checkout_abandon'     => round($checkoutAbandon, 6),
        'revenue_per_visit'    => round($revenue / $visits, 4),
        'avg_session_products' => round($sessionProducts / $visits, 2),
    ];
}

// ─── Funnel drop-off ───────────────────────────────────────────────────────────

/**
 * Identify where users leave the funnel and by what percentage.
 *
 * @param array $funnel Ordered keys representing funnel stages, each with
 *                      an integer count.
 *                      Example: ['visit'=>1000,'product_view'=>600,
 *                                'add_to_cart'=>200,'checkout_start'=>120,
 *                                'purchase'=>80]
 * @return array[] Each entry: {stage, count, drop_count, drop_pct}
 *                 drop_pct is the fraction lost relative to the prior stage.
 */
function get_drop_points(array $funnel): array
{
    $stages = array_keys($funnel);
    $counts = array_values($funnel);
    $result = [];

    for ($i = 0; $i < count($stages); $i++) {
        $prev      = $i > 0 ? max(1, (int)$counts[$i - 1]) : max(1, (int)$counts[$i]);
        $current   = max(0, (int)$counts[$i]);
        $dropCount = $i > 0 ? max(0, (int)$counts[$i - 1] - $current) : 0;
        $dropPct   = $i > 0 ? round($dropCount / $prev, 6) : 0.0;

        $result[] = [
            'stage'      => $stages[$i],
            'count'      => $current,
            'drop_count' => $dropCount,
            'drop_pct'   => $dropPct,
        ];
    }

    // Sort by highest drop percentage (worst bottlenecks first), preserving stage order
    usort($result, static fn($a, $b) => $b['drop_pct'] <=> $a['drop_pct']);

    return $result;
}

// ─── Hour-of-day analysis ──────────────────────────────────────────────────────

/**
 * Return hours of day ranked by conversion rate.
 *
 * @param array[] $events Each event must have: type (string), ts (unix timestamp).
 *                        Types: 'visit', 'purchase'
 * @return array[] Each: {hour (0-23), visits, purchases, conversion_rate}
 *                 Sorted best conversion first.
 */
function identify_best_hours(array $events): array
{
    $hourVisits    = array_fill(0, 24, 0);
    $hourPurchases = array_fill(0, 24, 0);

    foreach ($events as $ev) {
        if (empty($ev['ts'])) {
            continue;
        }
        $h = (int)date('G', (int)$ev['ts']); // 0–23
        $type = (string)($ev['type'] ?? '');

        if ($type === 'visit') {
            $hourVisits[$h]++;
        } elseif ($type === 'purchase') {
            $hourPurchases[$h]++;
        }
    }

    $rows = [];
    for ($h = 0; $h < 24; $h++) {
        $v = $hourVisits[$h];
        $p = $hourPurchases[$h];
        $rows[] = [
            'hour'            => $h,
            'visits'          => $v,
            'purchases'       => $p,
            'conversion_rate' => $v > 0 ? round($p / $v, 6) : 0.0,
        ];
    }

    usort($rows, static fn($a, $b) => $b['conversion_rate'] <=> $a['conversion_rate']);

    return $rows;
}

// ─── Worst-product detection ───────────────────────────────────────────────────

/**
 * Find products with high view counts but low add-to-cart rates.
 *
 * @param array[] $events Each event: type ('product_view'|'add_to_cart'),
 *                        product_id (string|int), ts (unix timestamp)
 * @return array[] Products sorted by cart_rate ASC (worst first).
 *                 Each: {product_id, views, cart_adds, cart_rate}
 *                 Only includes products with at least 10 views.
 */
function identify_worst_products(array $events): array
{
    $views    = [];
    $cartAdds = [];

    foreach ($events as $ev) {
        $pid  = (string)($ev['product_id'] ?? '');
        $type = (string)($ev['type'] ?? '');
        if ($pid === '') {
            continue;
        }

        if ($type === 'product_view') {
            $views[$pid] = ($views[$pid] ?? 0) + 1;
        } elseif ($type === 'add_to_cart') {
            $cartAdds[$pid] = ($cartAdds[$pid] ?? 0) + 1;
        }
    }

    $rows = [];
    foreach ($views as $pid => $viewCount) {
        if ($viewCount < 10) {
            continue; // not enough data
        }
        $adds     = $cartAdds[$pid] ?? 0;
        $cartRate = round($adds / $viewCount, 6);
        $rows[]   = [
            'product_id' => $pid,
            'views'      => $viewCount,
            'cart_adds'  => $adds,
            'cart_rate'  => $cartRate,
        ];
    }

    // Worst performers first
    usort($rows, static fn($a, $b) => $a['cart_rate'] <=> $b['cart_rate']);

    return $rows;
}

// ─── Report generation ─────────────────────────────────────────────────────────

/**
 * Generate a human-readable text report for the last N hours of activity.
 *
 * Reads events from the shared log at autodev/core/events.log.
 * Falls back gracefully if the log does not exist.
 *
 * @param int $hours Look-back window in hours (default 24).
 * @return string Formatted report string.
 */
function generate_report(int $hours = 24): string
{
    $logPath  = __DIR__ . '/../../autodev/core/events.log';
    $since    = time() - ($hours * 3600);
    $events   = _conv_load_events($logPath, $since);

    // Aggregate raw counts for analyze_conversion()
    $agg = ['visits' => 0, 'sales' => 0, 'checkout_start' => 0,
            'orders' => 0, 'revenue' => 0.0, 'session_product_views' => 0];

    foreach ($events as $ev) {
        switch ($ev['type'] ?? '') {
            case 'visit':          $agg['visits']++;               break;
            case 'purchase':       $agg['sales']++;
                                   $agg['orders']++;
                                   $agg['revenue'] += (float)($ev['revenue'] ?? 0); break;
            case 'checkout_start': $agg['checkout_start']++;       break;
            case 'product_view':   $agg['session_product_views']++; break;
        }
    }

    $metrics      = analyze_conversion($agg);
    $worstProds   = array_slice(identify_worst_products($events), 0, 5);
    $bestHours    = array_slice(identify_best_hours($events), 0, 3);

    $funnel = [
        'visit'          => $agg['visits'],
        'product_view'   => $agg['session_product_views'],
        'checkout_start' => $agg['checkout_start'],
        'purchase'       => $agg['orders'],
    ];
    $dropPoints = get_drop_points($funnel);

    $ts     = date('Y-m-d H:i:s');
    $lines  = [];
    $lines[] = "=== AutoDev Conversion Report — Last {$hours}h ({$ts}) ===";
    $lines[] = '';
    $lines[] = '--- Overview ---';
    $lines[] = sprintf('  Visits              : %d', $agg['visits']);
    $lines[] = sprintf('  Conversion Rate     : %.2f%%', $metrics['conversion_rate'] * 100);
    $lines[] = sprintf('  Checkout Abandon    : %.2f%%', $metrics['checkout_abandon'] * 100);
    $lines[] = sprintf('  Revenue / Visit     : R$ %.2f', $metrics['revenue_per_visit']);
    $lines[] = sprintf('  Avg Products/Session: %.1f', $metrics['avg_session_products']);
    $lines[] = '';
    $lines[] = '--- Funnel Drop-off (worst first) ---';
    foreach ($dropPoints as $dp) {
        if ($dp['drop_pct'] > 0) {
            $lines[] = sprintf('  %-20s: -%.1f%% (%d users lost)',
                $dp['stage'], $dp['drop_pct'] * 100, $dp['drop_count']);
        }
    }
    $lines[] = '';
    $lines[] = '--- Best Conversion Hours ---';
    foreach ($bestHours as $bh) {
        $lines[] = sprintf('  %02d:00  conv=%.2f%%  visits=%d',
            $bh['hour'], $bh['conversion_rate'] * 100, $bh['visits']);
    }
    $lines[] = '';
    $lines[] = '--- Worst Products (high views, low cart rate) ---';
    if (empty($worstProds)) {
        $lines[] = '  (insufficient data)';
    }
    foreach ($worstProds as $wp) {
        $lines[] = sprintf('  Product %-15s  views=%d  cart_rate=%.2f%%',
            $wp['product_id'], $wp['views'], $wp['cart_rate'] * 100);
    }
    $lines[] = '';
    $lines[] = '=== End of Report ===';

    return implode(PHP_EOL, $lines);
}

function autodev_conversion_snapshot(int $hours = 24): array
{
    $metrics = autodev_calculate_metrics($hours);
    $funnel = autodev_get_funnel($hours);

    return [
        'summary' => analyze_conversion([
            'visits' => $funnel['visits'],
            'sales' => $funnel['orders'],
            'checkout_start' => $funnel['checkout_start'],
            'orders' => $funnel['orders'],
            'revenue' => (float)($metrics['revenue_estimate'] ?? 0),
            'session_product_views' => $funnel['product_views'],
        ]),
        'metrics' => $metrics,
        'funnel' => $funnel,
        'drop_points' => get_drop_points([
            'visit' => $funnel['visits'],
            'product_view' => $funnel['product_views'],
            'add_to_cart' => $funnel['add_to_cart'],
            'checkout_start' => $funnel['checkout_start'],
            'checkout_submit' => $funnel['checkout_submit'],
            'purchase' => $funnel['orders'],
        ]),
    ];
}

// ─── Internal helpers ──────────────────────────────────────────────────────────

/**
 * Load and parse JSONL events from a log file since a given timestamp.
 *
 * @internal
 */
function _conv_load_events(string $path, int $since): array
{
    if (!file_exists($path)) {
        return [];
    }

    $events = [];
    $handle = fopen($path, 'r');
    if ($handle === false) {
        return [];
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
        $ts = (int)($ev['ts'] ?? $ev['time'] ?? 0);
        if ($ts >= $since) {
            $events[] = $ev;
        }
    }
    fclose($handle);

    return $events;
}
