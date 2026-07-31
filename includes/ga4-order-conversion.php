<?php
declare(strict_types=1);

/**
 * Sends a deduplicated GA4 purchase only after payment approval.
 * No customer PII is included. Returns false when GA4 is not configured or
 * when the Measurement Protocol endpoint does not accept the request.
 */
function svga4_send_approved_purchase(array $order): bool
{
    $measurementId = trim((string)(getenv('GA4_ID') ?: getenv('GOOGLE_ANALYTICS_ID') ?: ''));
    $apiSecret = trim((string)(getenv('GA4_SECRET') ?: ''));
    $orderNumber = trim((string)($order['order_number'] ?? ''));
    if ($measurementId === '' || $apiSecret === '' || $orderNumber === '') {
        return false;
    }

    $items = [];
    foreach (is_array($order['items'] ?? null) ? $order['items'] : [] as $item) {
        if (!is_array($item)) continue;
        $items[] = [
            'item_id' => (string)($item['sku'] ?? $item['olist_product_id'] ?? $item['id'] ?? ''),
            'item_name' => (string)($item['name'] ?? 'Produto Vivaliz'),
            'item_brand' => (string)($item['brand'] ?? 'Vivaliz'),
            'item_category' => (string)($item['category'] ?? ''),
            'price' => round((float)($item['price'] ?? 0), 2),
            'quantity' => max(1, (int)($item['quantity'] ?? 1)),
        ];
    }

    $payload = [
        'client_id' => 'server.' . substr(hash('sha256', $orderNumber), 0, 24),
        'timestamp_micros' => (int)(microtime(true) * 1000000),
        'non_personalized_ads' => false,
        'events' => [[
            'name' => 'purchase',
            'params' => [
                'transaction_id' => $orderNumber,
                'currency' => 'BRL',
                'value' => round((float)($order['total'] ?? 0), 2),
                'shipping' => round((float)($order['shipping_total'] ?? 0), 2),
                'coupon' => (string)($order['coupon_code'] ?? ''),
                'payment_type' => (string)($order['payment_method'] ?? ''),
                'items' => $items,
                'engagement_time_msec' => 1,
                'session_id' => (int)substr(hash('crc32b', $orderNumber), 0, 8),
            ],
        ]],
    ];

    $url = 'https://www.google-analytics.com/mp/collect?measurement_id=' . rawurlencode($measurementId)
        . '&api_secret=' . rawurlencode($apiSecret);
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);
    curl_exec($handle);
    $error = curl_errno($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    $success = $error === 0 && in_array($status, [200, 204], true);
    error_log('[GA4Purchase] order=' . $orderNumber . ' success=' . ($success ? 'yes' : 'no') . ' status=' . $status);
    return $success;
}
