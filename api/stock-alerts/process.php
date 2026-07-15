<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function svsap_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function svsap_root(): string
{
    return dirname(__DIR__, 2);
}

function svsap_data_dir(): string
{
    $preferred = svsap_root() . '/storage/stock-alerts';
    if ((is_dir($preferred) || @mkdir($preferred, 0755, true)) && is_writable($preferred)) {
        return $preferred;
    }

    $fallback = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'shopvivaliz-stock-alerts';
    if ((is_dir($fallback) || @mkdir($fallback, 0755, true)) && is_writable($fallback)) {
        return $fallback;
    }

    return '';
}

function svsap_normalize_sku(string $sku): string
{
    $sku = strtoupper(trim($sku));
    $sku = preg_replace('/[^A-Z0-9._-]/', '', $sku) ?: '';
    return substr($sku, 0, 80);
}

function svsap_load_json_array(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function svsap_stock_map(): array
{
    $products = svsap_load_json_array(svsap_root() . '/api/catalog/fallback-products.json');
    $map = [];
    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }
        $sku = svsap_normalize_sku((string)($product['sku'] ?? ''));
        if ($sku === '') {
            continue;
        }
        $map[$sku] = [
            'sku' => $sku,
            'name' => trim((string)($product['name'] ?? $product['description'] ?? $sku)),
            'stock' => max(0, (int)($product['stock'] ?? 0)),
        ];
    }
    return $map;
}

if (PHP_SAPI !== 'cli') {
    svsap_json(403, ['ok' => false, 'error' => 'cli_only']);
}

$dir = svsap_data_dir();
if ($dir === '') {
    svsap_json(500, ['ok' => false, 'error' => 'storage_unavailable']);
}

$subscribersFile = $dir . '/subscribers.jsonl';
$outboxFile = $dir . '/outbox.jsonl';
$subscribers = [];
if (is_file($subscribersFile)) {
    foreach (file($subscribersFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $subscribers[] = $row;
        }
    }
}

$stocks = svsap_stock_map();
$queued = 0;
$checked = 0;
$now = gmdate('c');

foreach ($subscribers as &$subscriber) {
    $status = (string)($subscriber['status'] ?? 'pending');
    if ($status !== 'pending') {
        continue;
    }

    $checked++;
    $sku = svsap_normalize_sku((string)($subscriber['sku'] ?? ''));
    $product = $stocks[$sku] ?? null;
    if (!$product || (int)$product['stock'] <= 0) {
        $subscriber['last_checked_at'] = $now;
        continue;
    }

    $message = [
        'type' => 'stock_available',
        'channel' => 'notification_pending',
        'subscription_id' => (string)($subscriber['id'] ?? ''),
        'sku' => $sku,
        'email' => strtolower(trim((string)($subscriber['email'] ?? ''))),
        'product_name' => trim((string)($subscriber['product_name'] ?? '')) ?: $product['name'],
        'stock' => (int)$product['stock'],
        'created_at' => $now,
        'governance' => [
            'price_changed' => false,
            'campaign_published' => false,
            'deploy_triggered' => false,
        ],
    ];

    $ok = @file_put_contents(
        $outboxFile,
        json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
    if ($ok === false) {
        svsap_json(500, ['ok' => false, 'error' => 'outbox_write_failed']);
    }

    $subscriber['status'] = 'queued';
    $subscriber['queued_at'] = $now;
    $subscriber['last_checked_at'] = $now;
    $queued++;
}
unset($subscriber);

$tmp = $subscribersFile . '.tmp';
$payload = '';
foreach ($subscribers as $subscriber) {
    $payload .= json_encode($subscriber, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
}
if (@file_put_contents($tmp, $payload, LOCK_EX) === false || !@rename($tmp, $subscribersFile)) {
    @unlink($tmp);
    svsap_json(500, ['ok' => false, 'error' => 'subscribers_write_failed']);
}

svsap_json(200, [
    'ok' => true,
    'checked_pending' => $checked,
    'queued_notifications' => $queued,
    'subscribers_total' => count($subscribers),
    'outbox' => $outboxFile,
]);
