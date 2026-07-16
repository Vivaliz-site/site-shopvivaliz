<?php
/**
 * ShopVivaliz external smoke test.
 * Usage:
 *   php tools/external-smoke-test.php https://dev.shopvivaliz.com.br 9.2.69-external-monitor-agent
 */
$base = rtrim($argv[1] ?? getenv('SHOPVIVALIZ_BASE_URL') ?: 'https://dev.shopvivaliz.com.br', '/');
$expectedVersion = $argv[2] ?? getenv('SHOPVIVALIZ_EXPECTED_VERSION') ?: '';
$timeout = (int)(getenv('SHOPVIVALIZ_SMOKE_TIMEOUT') ?: 20);

$checks = [
    ['name' => 'home', 'path' => '/', 'type' => 'html', 'required' => true],
    ['name' => 'update_applied', 'path' => '/installer/update-applied-check.php', 'type' => 'json', 'required' => true],
    ['name' => 'self_test', 'path' => '/installer/self-test.php', 'type' => 'json', 'required' => true],
    ['name' => 'olist_oauth_admin', 'path' => '/admin/olist-oauth.php', 'type' => 'html', 'required' => false],
    ['name' => 'olist_sync_dry_run', 'path' => '/olist/sync-products.php?dry_run=1&expected=200&limit=50', 'type' => 'json', 'required' => false],
    ['name' => 'shipping_check', 'path' => '/api/melhorenvio/shipping-check.php?product_id=1&cep=01001000', 'type' => 'json', 'required' => false],
    ['name' => 'melhorenvio_webhook', 'path' => '/api/melhorenvio/webhook.php', 'type' => 'json', 'required' => false],
    ['name' => 'olist_webhook', 'path' => '/api/olist/webhook.php', 'type' => 'json', 'required' => false],
    ['name' => 'pagarme_webhook', 'path' => '/api/webhooks/pagarme.php', 'type' => 'json', 'required' => false],
];

function fetch_url(string $url, int $timeout): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'ShopVivalizExternalMonitor/1.0',
        CURLOPT_HEADER => true,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $totalTime = (float)curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);
    if ($raw === false) {
        return ['status' => 0, 'body' => '', 'error' => $err, 'time' => $totalTime];
    }
    return ['status' => $status, 'body' => substr($raw, $headerSize), 'error' => $err, 'time' => $totalTime];
}

$results = [];
$ok = true;
foreach ($checks as $check) {
    $url = $base . $check['path'];
    $res = fetch_url($url, $timeout);
    $item = [
        'name' => $check['name'],
        'url' => $url,
        'status' => $res['status'],
        'time_seconds' => round($res['time'], 3),
        'ok' => true,
        'errors' => [],
    ];
    if ($res['status'] >= 500 || $res['status'] === 0) {
        $item['ok'] = false;
        $item['errors'][] = 'HTTP failure or timeout';
    }
    if ($check['required'] && !in_array($res['status'], [200, 301, 302], true)) {
        $item['ok'] = false;
        $item['errors'][] = 'Required endpoint did not return 200/301/302';
    }
    if ($check['type'] === 'json' && $res['status'] === 200) {
        $json = json_decode($res['body'], true);
        if (!is_array($json)) {
            $item['ok'] = false;
            $item['errors'][] = 'Invalid JSON';
        } else {
            $item['json_status'] = $json['status'] ?? null;
            $item['json_ok'] = $json['ok'] ?? null;
            $item['version'] = $json['version'] ?? null;
            if ($check['name'] === 'update_applied' && $expectedVersion !== '' && (($json['version'] ?? '') !== $expectedVersion)) {
                $item['ok'] = false;
                $item['errors'][] = 'Version mismatch: expected ' . $expectedVersion . ', got ' . ($json['version'] ?? 'none');
            }
            if ($check['name'] === 'olist_sync_dry_run') {
                $before = $json['before_count'] ?? null;
                $after = $json['after_count'] ?? null;
                $expected = $json['expected'] ?? null;
                if ($expected && $before !== null && $after !== null && $after <= $before && $after < $expected) {
                    $item['ok'] = false;
                    $item['errors'][] = 'Olist count is stuck below expected';
                }
            }
        }
    }
    if ($check['name'] === 'home' && $res['status'] === 200) {
        $body = mb_strtolower($res['body']);
        $item['has_buy_word'] = (strpos($body, 'comprar') !== false);
        $item['has_cep_word'] = (strpos($body, 'cep') !== false);
    }
    if (!$item['ok'] && $check['required']) {
        $ok = false;
    }
    if (!$item['ok'] && in_array($check['name'], ['update_applied','self_test','shipping_check','olist_sync_dry_run'], true)) {
        $ok = false;
    }
    $results[] = $item;
}

$out = [
    'ok' => $ok,
    'base_url' => $base,
    'expected_version' => $expectedVersion,
    'checked_at' => date('c'),
    'checks' => $results,
];
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($ok ? 0 : 1);
