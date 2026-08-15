<?php
declare(strict_types=1);

/**
 * Splits the browser identity persisted with the order.
 *
 * Historical orders contain only a GA4 client_id. New orders can carry
 * "client_id|session_cookie" so the approved Measurement Protocol purchase can
 * be joined back to the same tagged GA4 session.
 *
 * @return array{client_id:string,session_id:string}
 */
function svga4_order_browser_identity(array $order): array
{
    $candidate = trim((string)($order['funnel_client_id'] ?? ''));
    if ($candidate === '') {
        return ['client_id' => '', 'session_id' => ''];
    }

    $parts = explode('|', $candidate, 2);
    $clientId = trim((string)($parts[0] ?? ''));
    $sessionId = trim((string)($parts[1] ?? ''));

    if (preg_match('/^\d+\.\d+$/', $clientId) !== 1) {
        return ['client_id' => '', 'session_id' => ''];
    }

    // Measurement Protocol session_id must be numeric. Normalize historical cookie values.
    if ($sessionId !== '' && preg_match('/^\d+$/', $sessionId) !== 1) {
        if (preg_match('/^GS\d+(?:\.\d+)?\.(\d+)/', $sessionId, $legacy) === 1) {
            $sessionId = (string)$legacy[1];
        } elseif (preg_match('/(?:^|\$)s(\d+)(?:\$|$)/', $sessionId, $modern) === 1) {
            $sessionId = (string)$modern[1];
        } else {
            $sessionId = '';
        }
    }
    if ($sessionId !== '' && (preg_match('/^\d+$/', $sessionId) !== 1 || strlen($sessionId) > 20)) {
        $sessionId = '';
    }

    return ['client_id' => $clientId, 'session_id' => $sessionId];
}

/**
 * Returns the GA4 client_id that can be joined back to browser tagging.
 * New orders persist the client_id extracted from the consented _ga cookie in
 * funnel_client_id. Older orders may contain a random first-party id, so only
 * the canonical numeric GA format is treated as attributable.
 */
function svga4_order_client_id(array $order): array
{
    $browserIdentity = svga4_order_browser_identity($order);
    if ($browserIdentity['client_id'] !== '') {
        return ['client_id' => $browserIdentity['client_id'], 'tagged' => true];
    }

    $orderNumber = trim((string)($order['order_number'] ?? ''));
    return [
        'client_id' => 'server.' . substr(hash('sha256', $orderNumber !== '' ? $orderNumber : uniqid('', true)), 0, 24),
        'tagged' => false,
    ];
}

function svga4_order_session_id(array $order): string
{
    return svga4_order_browser_identity($order)['session_id'];
}

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
        $itemPayload = [
            'item_id' => (string)($item['sku'] ?? $item['olist_product_id'] ?? $item['id'] ?? ''),
            'item_name' => (string)($item['name'] ?? 'Produto Vivaliz'),
            'price' => round((float)($item['price'] ?? 0), 2),
            'quantity' => max(1, (int)($item['quantity'] ?? 1)),
        ];
        $brand = trim((string)($item['brand'] ?? ''));
        $category = trim((string)($item['category'] ?? ''));
        if ($brand !== '') $itemPayload['item_brand'] = $brand;
        if ($category !== '') $itemPayload['item_category'] = $category;
        $items[] = $itemPayload;
    }

    $identity = svga4_order_client_id($order);
    $clientId = (string)$identity['client_id'];
    $taggedClient = (bool)$identity['tagged'];
    $sessionId = $taggedClient ? svga4_order_session_id($order) : '';

    $eventParams = [
        'transaction_id' => $orderNumber,
        'currency' => 'BRL',
        'value' => round((float)($order['total'] ?? 0), 2),
        'shipping' => round((float)($order['shipping_total'] ?? 0), 2),
        'coupon' => (string)($order['coupon_code'] ?? ''),
        'payment_type' => (string)($order['payment_method'] ?? ''),
        'items' => $items,
        'engagement_time_msec' => 1,
    ];
    if ($sessionId !== '') {
        $eventParams['session_id'] = $sessionId;
    }

    $payload = [
        'client_id' => $clientId,
        'timestamp_micros' => (int)(microtime(true) * 1000000),
        // When there is no browser client id to inherit consent state from,
        // keep the fallback purchase out of personalised advertising use.
        'non_personalized_ads' => !$taggedClient,
        'events' => [[
            'name' => 'purchase',
            'params' => $eventParams,
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
    error_log(
        '[GA4Purchase] order=' . $orderNumber
        . ' success=' . ($success ? 'yes' : 'no')
        . ' status=' . $status
        . ' attribution=' . ($taggedClient ? 'browser_client_id' : 'server_fallback')
        . ' session=' . ($sessionId !== '' ? 'joined' : 'none')
    );
    return $success;
}
