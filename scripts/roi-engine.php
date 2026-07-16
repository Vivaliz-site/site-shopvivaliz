<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function roi_root(): string
{
    return dirname(__DIR__);
}

function roi_path(string $rel): string
{
    return roi_root() . '/' . ltrim($rel, '/');
}

function roi_read_json_file(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

function roi_json(string $rel): array
{
    $decoded = roi_read_json_file(roi_path($rel));
    return is_array($decoded) ? $decoded : [];
}

function roi_first_existing_json(array $relativePaths): array
{
    foreach ($relativePaths as $rel) {
        $data = roi_json($rel);
        if (!empty($data)) {
            return [$rel, $data];
        }
    }

    return ['', []];
}

function roi_write_json(string $rel, array $payload): string
{
    $path = roi_path($rel);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    file_put_contents(
        $path,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        LOCK_EX
    );

    return $path;
}

function roi_parse_float(mixed $value, float $default = 0.0): float
{
    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }

    if (!is_string($value)) {
        return $default;
    }

    $clean = str_replace(['R$', ' ', ','], ['', '', '.'], trim($value));
    return is_numeric($clean) ? (float)$clean : $default;
}

function roi_parse_int(mixed $value, int $default = 0): int
{
    if (is_int($value)) {
        return $value;
    }

    if (is_float($value)) {
        return (int)$value;
    }

    if (!is_string($value)) {
        return $default;
    }

    return is_numeric(trim($value)) ? (int)$value : $default;
}

function roi_normalize_sales_row(array $row, string $source, string $channel = 'local'): ?array
{
    $sku = trim((string)($row['sku'] ?? $row['seller_sku'] ?? $row['merchant_sku'] ?? $row['item_sku'] ?? $row['product_sku'] ?? $row['id'] ?? ''));
    if ($sku === '') {
        $sku = trim((string)($row['name'] ?? $row['title'] ?? ''));
    }
    if ($sku === '') {
        return null;
    }

    $sales = roi_parse_int($row['sales'] ?? $row['quantity'] ?? $row['qty'] ?? $row['units_sold'] ?? 0, 0);
    $revenue = roi_parse_float($row['revenue'] ?? $row['gross_revenue'] ?? $row['total_amount'] ?? 0.0, 0.0);
    $price = roi_parse_float($row['price'] ?? $row['unit_price'] ?? $row['sale_price'] ?? 0.0, 0.0);
    if ($revenue <= 0.0 && $price > 0.0 && $sales > 0) {
        $revenue = $price * $sales;
    }

    $fees = roi_parse_float($row['fees'] ?? $row['commission'] ?? $row['marketplace_fee'] ?? 0.0, 0.0);
    $costSource = 'reported';
    $cost = null;
    if (array_key_exists('cost', $row)) {
        $cost = roi_parse_float($row['cost'], 0.0);
    } elseif (array_key_exists('cogs', $row)) {
        $cost = roi_parse_float($row['cogs'], 0.0);
    } elseif (array_key_exists('estimated_cost', $row)) {
        $cost = roi_parse_float($row['estimated_cost'], 0.0);
    }

    if ($cost === null || $cost <= 0.0) {
        $defaultRate = max(0.0, min(1.0, roi_parse_float(getenv('ROI_DEFAULT_COST_RATE') ?: '0.50', 0.50)));
        $cost = $revenue > 0 ? $revenue * $defaultRate : 0.0;
        $costSource = 'estimated';
    }

    $profit = array_key_exists('profit', $row)
        ? roi_parse_float($row['profit'], 0.0)
        : ($revenue - $cost - $fees);

    $margin = array_key_exists('margin', $row)
        ? roi_parse_float($row['margin'], 0.0)
        : ($revenue > 0 ? ($profit / $revenue) * 100.0 : 0.0);

    return [
        'sku' => $sku,
        'name' => (string)($row['name'] ?? $row['title'] ?? $sku),
        'sales' => $sales,
        'revenue' => round($revenue, 2),
        'cost' => round($cost, 2),
        'profit' => round($profit, 2),
        'margin' => round($margin, 2),
        'channel' => (string)($row['channel'] ?? $channel),
        'source' => $source,
        'cost_source' => $costSource,
        'target' => (string)($row['target'] ?? $row['url'] ?? '/produto/' . rawurlencode($sku)),
    ];
}

function roi_reduce_rows(array $rows, string $source, string $channel = 'local'): array
{
    $indexed = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $normalized = roi_normalize_sales_row($row, $source, $channel);
        if ($normalized === null) {
            continue;
        }

        $key = strtolower($normalized['sku']);
        if (!isset($indexed[$key])) {
            $indexed[$key] = $normalized;
            continue;
        }

        $current = $indexed[$key];
        $indexed[$key] = [
            'sku' => $current['sku'],
            'name' => $current['name'] !== $current['sku'] ? $current['name'] : $normalized['name'],
            'sales' => $current['sales'] + $normalized['sales'],
            'revenue' => round($current['revenue'] + $normalized['revenue'], 2),
            'cost' => round($current['cost'] + $normalized['cost'], 2),
            'profit' => round($current['profit'] + $normalized['profit'], 2),
            'margin' => 0.0,
            'channel' => $normalized['channel'] !== '' ? $normalized['channel'] : $current['channel'],
            'source' => $current['source'] . ',' . $normalized['source'],
            'cost_source' => $current['cost_source'] === 'estimated' || $normalized['cost_source'] === 'estimated'
                ? 'estimated'
                : 'reported',
            'target' => $normalized['target'] ?: $current['target'],
        ];

        $indexed[$key]['margin'] = $indexed[$key]['revenue'] > 0
            ? round(($indexed[$key]['profit'] / $indexed[$key]['revenue']) * 100.0, 2)
            : 0.0;
    }

    return array_values($indexed);
}

function roi_extract_from_payload(array $payload, string $source, string $channel = 'local'): array
{
    if (isset($payload['products']) && is_array($payload['products'])) {
        return roi_reduce_rows($payload['products'], $source, $channel);
    }

    if (isset($payload['orders']) && is_array($payload['orders'])) {
        $rows = [];
        foreach ($payload['orders'] as $order) {
            if (!is_array($order)) {
                continue;
            }
            $orderItems = [];
            if (isset($order['items']) && is_array($order['items'])) {
                $orderItems = $order['items'];
            } elseif (isset($order['order_items']) && is_array($order['order_items'])) {
                $orderItems = $order['order_items'];
            }
            foreach ($orderItems as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $item['channel'] = $item['channel'] ?? $channel;
                $item['sales'] = $item['sales'] ?? $item['quantity'] ?? 1;
                if (!isset($item['revenue']) && (isset($item['price']) || isset($item['sale_price']))) {
                    $item['revenue'] = roi_parse_float($item['price'] ?? $item['sale_price'], 0.0) * roi_parse_int($item['sales'], 1);
                }
                if (!isset($item['target'])) {
                    $item['target'] = (string)($item['url'] ?? ($item['sku'] ?? ''));
                }
                $rows[] = $item;
            }
        }
        return roi_reduce_rows($rows, $source, $channel);
    }

    if (array_is_list($payload)) {
        return roi_reduce_rows($payload, $source, $channel);
    }

    return [];
}

function roi_load_optional_source(string $label, string $source, string $channel = 'local', bool $useMlClient = false): array
{
    $source = trim($source);
    if ($source === '') {
        return [];
    }

    $payload = null;
    $meta = ['label' => $label, 'source' => $source, 'status' => 'missing'];

    if (preg_match('~^https?://~i', $source)) {
        if ($useMlClient) {
            $client = roi_path('api/ml/client.php');
            if (is_file($client)) {
                require_once $client;
                if (function_exists('ml_http_get')) {
                    try {
                        $payload = ml_http_get($source);
                        $meta['status'] = 'loaded';
                    } catch (Throwable $e) {
                        $meta['status'] = 'error';
                        $meta['error'] = $e->getMessage();
                        return ['meta' => $meta, 'products' => []];
                    }
                }
            }
        }

        if ($payload === null) {
            $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 20, 'ignore_errors' => true]]);
            $body = @file_get_contents($source, false, $ctx);
            if ($body !== false) {
                $decoded = json_decode($body, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                    $meta['status'] = 'loaded';
                }
            }
        }
    } else {
        $path = is_file($source) ? $source : roi_path($source);
        $payload = roi_read_json_file($path);
        if (is_array($payload)) {
            $meta['status'] = 'loaded';
            $meta['source'] = $path;
        }
    }

    if (!is_array($payload)) {
        return ['meta' => $meta, 'products' => []];
    }

    return [
        'meta' => $meta,
        'products' => roi_extract_from_payload($payload, $meta['source'], $channel),
    ];
}

function roi_cycle_history(): array
{
    $history = [];
    $cycleLog = roi_read_json_file(roi_path('scripts/autonomous-cycle-log.json'));
    if (is_array($cycleLog) && !empty($cycleLog)) {
        $history[] = [
            'task_id' => (string)($cycleLog['next_task']['id'] ?? $cycleLog['task']['id'] ?? ''),
            'status' => (string)($cycleLog['status'] ?? 'unknown'),
            'result' => (string)($cycleLog['result']['status'] ?? 'unknown'),
            'generated_at' => (string)($cycleLog['generated_at'] ?? $cycleLog['last_cycle_at'] ?? ''),
        ];
    }

    $eventsPath = roi_path('logs/autonomous-cycle-events.jsonl');
    if (is_file($eventsPath)) {
        $lines = file($eventsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $event = json_decode($line, true);
            if (!is_array($event)) {
                continue;
            }
            $history[] = [
                'task_id' => (string)($event['next_task']['id'] ?? $event['selection']['task']['id'] ?? ''),
                'status' => (string)($event['auto_audit']['status'] ?? 'unknown'),
                'result' => (string)($event['result']['status'] ?? 'unknown'),
                'generated_at' => (string)($event['generated_at'] ?? ''),
            ];
        }
    }

    return $history;
}

function roi_task_recommendations(array $history): array
{
    $scores = [];
    foreach ($history as $entry) {
        $taskId = trim((string)($entry['task_id'] ?? ''));
        if ($taskId === '') {
            continue;
        }

        if (!isset($scores[$taskId])) {
            $scores[$taskId] = ['success' => 0, 'failure' => 0, 'last_seen' => null];
        }

        $result = strtolower((string)($entry['result'] ?? ''));
        $status = strtolower((string)($entry['status'] ?? ''));
        if (in_array($result, ['active', 'success', 'ok'], true) || $status === 'healthy') {
            $scores[$taskId]['success']++;
        } elseif (in_array($result, ['failed', 'error', 'idle'], true) || $status === 'critical') {
            $scores[$taskId]['failure']++;
        }

        $scores[$taskId]['last_seen'] = $entry['generated_at'] ?? $scores[$taskId]['last_seen'];
    }

    $rows = [];
    foreach ($scores as $taskId => $score) {
        $net = $score['success'] - $score['failure'];
        $rows[] = [
            'task_id' => $taskId,
            'success' => $score['success'],
            'failure' => $score['failure'],
            'net_score' => $net,
            'recommendation' => $net > 0 ? 'repetir' : 'reduzir_prioridade',
            'last_seen' => $score['last_seen'],
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        $cmp = $b['net_score'] <=> $a['net_score'];
        if ($cmp !== 0) {
            return $cmp;
        }

        $cmp = $b['success'] <=> $a['success'];
        if ($cmp !== 0) {
            return $cmp;
        }

        $cmp = $a['failure'] <=> $b['failure'];
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp($a['task_id'], $b['task_id']);
    });

    return array_slice($rows, 0, 10);
}

function roi_action_for(array $product): array
{
    $sales = roi_parse_int($product['sales'] ?? 0, 0);
    $profit = roi_parse_float($product['profit'] ?? 0, 0.0);
    $margin = roi_parse_float($product['margin'] ?? 0, 0.0);
    $channel = strtolower((string)($product['channel'] ?? 'local'));
    $channelNormalized = str_replace(['_', '-'], ' ', $channel);

    $priorityLabel = 'NEUTRO';
    $type = 'catalog';
    $action = 'melhorar_copy';
    $impact = 'medio';
    $priorityScore = 50;
    $reasons = [];

    if ($profit <= 0.0) {
        $priorityLabel = 'EVITAR';
        $type = 'efficiency';
        $action = 'reduzir_prioridade';
        $impact = 'baixo';
        $priorityScore = 15;
        $reasons[] = 'profit_non_positive';
    } elseif ($sales > 50 && $margin < 20) {
        $priorityLabel = 'OTIMIZAR_CONVERSAO';
        $type = 'conversion';
        $action = 'melhorar_copy';
        $impact = 'alto';
        $priorityScore = 95;
        $reasons[] = 'high_volume_low_margin';
    } elseif ($profit > 0 && $sales > 10) {
        $priorityLabel = 'MAXIMIZAR';
        $type = 'seo';
        $action = 'melhorar_seo';
        $impact = 'alto';
        $priorityScore = 90;
        $reasons[] = 'positive_profit_and_sales';
    } elseif ($sales > 0) {
        $priorityLabel = 'PRIORIZAR';
        $type = 'catalog';
        $action = 'refinar_pagina';
        $impact = 'medio';
        $priorityScore = 70;
        $reasons[] = 'sales_present';
    }

    if ($channel === 'shopee' && $type === 'seo') {
        $action = 'melhorar_seo_shopee';
        $reasons[] = 'channel_shopee';
    } elseif (in_array($channelNormalized, ['mercado livre', 'mercadolivre', 'ml'], true)) {
        if ($type === 'seo') {
            $action = 'otimizar_titulo_ml';
            $reasons[] = 'channel_mercado_livre';
        }
    }

    return [
        'sku' => (string)($product['sku'] ?? ''),
        'name' => (string)($product['name'] ?? $product['sku'] ?? ''),
        'type' => $type,
        'target' => (string)($product['target'] ?? '/produto/' . rawurlencode((string)($product['sku'] ?? ''))),
        'action' => $action,
        'impact' => $impact,
        'priority_label' => $priorityLabel,
        'priority_score' => $priorityScore,
        'channel' => (string)($product['channel'] ?? 'local'),
        'sales' => $sales,
        'revenue' => roi_parse_float($product['revenue'] ?? 0, 0.0),
        'cost' => roi_parse_float($product['cost'] ?? 0, 0.0),
        'profit' => round($profit, 2),
        'margin' => round($margin, 2),
        'source' => (string)($product['source'] ?? 'local'),
        'cost_source' => (string)($product['cost_source'] ?? 'reported'),
        'reasons' => $reasons,
    ];
}

function roi_build_report(): array
{
    [$businessSource, $businessPayload] = roi_first_existing_json([
        'business-metrics.json',
        'scripts/business-metrics.json',
    ]);
    [$salesSource, $salesPayload] = roi_first_existing_json([
        'scripts/sales-metrics.json',
        'sales-metrics.json',
    ]);

    $products = [];
    $sources = [];

    foreach ([[$businessSource, $businessPayload, 'local'], [$salesSource, $salesPayload, 'local']] as [$source, $payload, $channel]) {
        if ($source === '' || empty($payload)) {
            continue;
        }
        $sources[] = ['label' => $source, 'status' => 'loaded'];
        $items = roi_extract_from_payload($payload, $source, $channel);
        $products = array_merge($products, $items);
    }

    $roiShops = roi_load_optional_source(
        'shopee',
        (string)(getenv('ROI_SHOPEE_SALES_SOURCE') ?: getenv('SHOPEE_SALES_SOURCE') ?: getenv('SHOPEE_ORDERS_SOURCE') ?: ''),
        'shopee'
    );
    if (!empty($roiShops['products'])) {
        $products = array_merge($products, $roiShops['products']);
        $sources[] = $roiShops['meta'];
    }

    $roiMl = roi_load_optional_source(
        'mercado_livre',
        (string)(getenv('ROI_ML_ORDERS_SOURCE') ?: getenv('ML_ORDERS_SOURCE') ?: getenv('ML_SALES_SOURCE') ?: ''),
        'mercado_livre',
        true
    );
    if (!empty($roiMl['products'])) {
        $products = array_merge($products, $roiMl['products']);
        $sources[] = $roiMl['meta'];
    }

    $normalized = [];
    foreach ($products as $product) {
        if (!is_array($product) || !isset($product['sku'])) {
            continue;
        }
        $normalized[] = roi_action_for($product);
    }

    usort($normalized, static function (array $a, array $b): int {
        $cmp = $b['priority_score'] <=> $a['priority_score'];
        if ($cmp !== 0) {
            return $cmp;
        }

        $cmp = $b['sales'] <=> $a['sales'];
        if ($cmp !== 0) {
            return $cmp;
        }

        $cmp = $b['profit'] <=> $a['profit'];
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string)$a['sku'], (string)$b['sku']);
    });

    $history = roi_cycle_history();
    $taskRecommendations = roi_task_recommendations($history);

    $impactCounts = ['alto' => 0, 'medio' => 0, 'baixo' => 0];
    foreach ($normalized as $item) {
        $impact = (string)($item['impact'] ?? 'medio');
        if (!isset($impactCounts[$impact])) {
            $impactCounts[$impact] = 0;
        }
        $impactCounts[$impact]++;
    }

    return [
        'generated_at' => date('c'),
        'director_basis' => 'roi',
        'summary' => [
            'products_loaded' => count($normalized),
            'high_impact' => $impactCounts['alto'] ?? 0,
            'medium_impact' => $impactCounts['medio'] ?? 0,
            'low_impact' => $impactCounts['baixo'] ?? 0,
        ],
        'sources' => $sources,
        'priorities' => $normalized,
        'top_opportunities' => array_slice($normalized, 0, 10),
        'task_recommendations' => $taskRecommendations,
    ];
}

$report = roi_build_report();
$reportPath = roi_write_json('logs/roi-engine-report.json', $report);

$mdLines = [
    '# ROI Engine Report',
    '',
    '- Generated at: `' . $report['generated_at'] . '`',
    '- Director basis: `' . $report['director_basis'] . '`',
    '- Products loaded: `' . $report['summary']['products_loaded'] . '`',
    '- High impact: `' . $report['summary']['high_impact'] . '`',
    '- Medium impact: `' . $report['summary']['medium_impact'] . '`',
    '- Low impact: `' . $report['summary']['low_impact'] . '`',
    '',
    '## Top Opportunities',
];

foreach (array_slice($report['priorities'], 0, 5) as $item) {
    $mdLines[] = sprintf(
        '- `%s` %s -> %s (%s, score %d)',
        $item['sku'],
        $item['type'],
        $item['action'],
        $item['impact'],
        (int)$item['priority_score']
    );
}

if (!empty($report['task_recommendations'])) {
    $mdLines[] = '';
    $mdLines[] = '## Task Recommendations';
    foreach ($report['task_recommendations'] as $task) {
        $mdLines[] = sprintf(
            '- `%s` => %s (success=%d, failure=%d, net=%d)',
            $task['task_id'],
            $task['recommendation'],
            (int)$task['success'],
            (int)$task['failure'],
            (int)$task['net_score']
        );
    }
}

file_put_contents(roi_path('logs/roi-engine-report.md'), implode(PHP_EOL, $mdLines) . PHP_EOL, LOCK_EX);

if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    fwrite(STDERR, "[ROI] Report saved to: {$reportPath}" . PHP_EOL);
    exit(0);
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
