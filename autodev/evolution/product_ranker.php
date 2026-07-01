<?php
declare(strict_types=1);

/**
 * AutoDev Evolution — Product Ranker
 *
 * Ranks products by a weighted performance score derived from sales,
 * cart additions, and view counts.  Identifies low converters and
 * persists a ranked config for use by the storefront.
 *
 * Ranking JSON : autodev/data/product_ranking.json
 */

define('PRODUCT_RANKING_PATH', __DIR__ . '/../../autodev/data/product_ranking.json');

// ─── Scoring ──────────────────────────────────────────────────────────────────

/**
 * Score a single product.
 *
 * Formula: (sales × 3) + (cart_adds × 1.5) + (views × 0.5)
 *
 * @param array $p Keys: sales (int), cart_adds (int), views (int)
 * @return float
 */
function _product_score(array $p): float
{
    $sales    = max(0, (int)($p['sales']     ?? 0));
    $cartAdds = max(0, (int)($p['cart_adds'] ?? 0));
    $views    = max(0, (int)($p['views']     ?? 0));

    return ($sales * 3.0) + ($cartAdds * 1.5) + ($views * 0.5);
}

// ─── Event aggregation ────────────────────────────────────────────────────────

/**
 * Aggregate raw events into per-product metric arrays.
 *
 * @param array[] $events Each event: {type, product_id, [revenue]}
 *                        Types: 'product_view', 'add_to_cart', 'purchase'
 * @return array<string, array> product_id → {views, cart_adds, sales, revenue}
 */
function _aggregate_product_events(array $events): array
{
    $agg = [];

    foreach ($events as $ev) {
        $pid  = (string)($ev['product_id'] ?? '');
        $type = (string)($ev['type']       ?? '');
        if ($pid === '') {
            continue;
        }

        if (!isset($agg[$pid])) {
            $agg[$pid] = ['product_id' => $pid, 'views' => 0,
                          'cart_adds' => 0, 'sales' => 0, 'revenue' => 0.0];
        }

        match ($type) {
            'product_view' => $agg[$pid]['views']++,
            'add_to_cart'  => $agg[$pid]['cart_adds']++,
            'purchase'     => ($agg[$pid]['sales']++ && ($agg[$pid]['revenue'] += (float)($ev['revenue'] ?? 0))),
            default        => null,
        };
    }

    return $agg;
}

// ─── Public API ───────────────────────────────────────────────────────────────

/**
 * Rank products by weighted performance score.
 *
 * @param array[] $events  Raw event stream (see _aggregate_product_events).
 * @param int     $limit   Maximum products to return (default 20).
 * @return array[] Products sorted by score DESC, each with:
 *                 {product_id, views, cart_adds, sales, revenue, score,
 *                  cart_rate, conversion_rate}
 */
function rank_products(array $events, int $limit = 20): array
{
    $agg  = _aggregate_product_events($events);
    $rows = [];

    foreach ($agg as $pid => $p) {
        $score        = _product_score($p);
        $views        = max(1, $p['views']);
        $cartRate     = round($p['cart_adds'] / $views, 6);
        $convRate     = round($p['sales'] / $views, 6);

        $rows[] = array_merge($p, [
            'score'           => round($score, 4),
            'cart_rate'       => $cartRate,
            'conversion_rate' => $convRate,
        ]);
    }

    usort($rows, static fn($a, $b) => $b['score'] <=> $a['score']);

    return array_slice($rows, 0, max(1, $limit));
}

/**
 * Identify products whose conversion rate is below a threshold.
 *
 * Only products with at least 10 views are considered (too few views =
 * statistically unreliable).
 *
 * @param array[] $events    Raw event stream.
 * @param float   $threshold Conversion rate floor (default 0.01 = 1%).
 * @return array[] Low-performing products sorted by conversion_rate ASC,
 *                 each with {product_id, views, cart_adds, sales,
 *                 conversion_rate, cart_rate}
 */
function get_low_performers(array $events, float $threshold = 0.01): array
{
    $agg  = _aggregate_product_events($events);
    $rows = [];

    foreach ($agg as $pid => $p) {
        $views = (int)$p['views'];
        if ($views < 10) {
            continue;
        }
        $convRate = $p['sales'] / $views;
        if ($convRate >= $threshold) {
            continue;
        }
        $rows[] = [
            'product_id'      => $pid,
            'views'           => $views,
            'cart_adds'       => (int)$p['cart_adds'],
            'sales'           => (int)$p['sales'],
            'conversion_rate' => round($convRate, 6),
            'cart_rate'       => round($p['cart_adds'] / max(1, $views), 6),
        ];
    }

    usort($rows, static fn($a, $b) => $a['conversion_rate'] <=> $b['conversion_rate']);

    return $rows;
}

/**
 * Build the ranking config array (without saving to disk).
 *
 * Reads events from autodev/core/events.log (last 48 h by default).
 *
 * @return array Config structure ready for json_encode / apply_ranking().
 */
function generate_ranking_config(): array
{
    $logPath = __DIR__ . '/../../autodev/core/events.log';
    $since   = time() - (48 * 3600);
    $events  = _ranker_load_events($logPath, $since);

    $ranked       = rank_products($events, 20);
    $lowPerformers = get_low_performers($events);

    return [
        'generated_at'  => date('c'),
        'window_hours'  => 48,
        'total_products'=> count($ranked),
        'top_ranked'    => $ranked,
        'low_performers'=> $lowPerformers,
        'order'         => array_column($ranked, 'product_id'),
    ];
}

/**
 * Save the ranking config to product_ranking.json.
 *
 * @return array The config that was saved.
 */
function apply_ranking(): array
{
    $dir = dirname(PRODUCT_RANKING_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $config = generate_ranking_config();
    file_put_contents(
        PRODUCT_RANKING_PATH,
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    return $config;
}

// ─── Internal helpers ──────────────────────────────────────────────────────────

/** @internal */
function _ranker_load_events(string $path, int $since): array
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
