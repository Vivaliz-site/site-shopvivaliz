<?php
declare(strict_types=1);

function svgads_click_identifier(array $order): array
{
    foreach (['gclid', 'gbraid', 'wbraid'] as $key) {
        $value = trim((string)($order[$key] ?? ''));
        if ($value === '') {
            continue;
        }
        if (strlen($value) > 255 || preg_match('/^[A-Za-z0-9._-]+$/', $value) !== 1) {
            return ['', ''];
        }
        return [$key, $value];
    }
    return ['', ''];
}

function svgads_conversion_datetime(array $order): ?string
{
    $candidates = [
        $order['payment_evidence_at'] ?? null,
        $order['mercadopago']['date_approved'] ?? null,
        $order['updated_at'] ?? null,
        $order['created_at'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        $raw = trim((string)$candidate);
        if ($raw === '') {
            continue;
        }
        try {
            $dt = new DateTimeImmutable($raw);
            return $dt->format('Y-m-d H:i:sP');
        } catch (Throwable) {
            continue;
        }
    }
    return null;
}

function svgads_build_click_conversion(array $order, string $conversionActionResource): ?array
{
    $orderNumber = trim((string)($order['order_number'] ?? ''));
    $total = round((float)($order['total'] ?? 0), 2);
    $conversionActionResource = trim($conversionActionResource);
    [$idKey, $idValue] = svgads_click_identifier($order);
    $conversionDateTime = svgads_conversion_datetime($order);

    if ($orderNumber === '' || $total <= 0 || $conversionActionResource === '' || $idKey === '' || $conversionDateTime === null) {
        return null;
    }

    return [
        $idKey => $idValue,
        'conversionAction' => $conversionActionResource,
        'conversionDateTime' => $conversionDateTime,
        'conversionValue' => $total,
        'currencyCode' => 'BRL',
        'orderId' => $orderNumber,
    ];
}

function svgads_http_post_json(string $url, array $headers, array $payload): array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($handle);
    $errno = curl_errno($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    return [$status, $errno, is_string($body) ? $body : ''];
}

function svgads_access_token(): string
{
    $clientId = trim((string)(getenv('GOOGLE_OAUTH_CLIENT_ID') ?: ''));
    $clientSecret = trim((string)(getenv('GOOGLE_OAUTH_CLIENT_SECRET') ?: ''));
    $refreshToken = trim((string)(getenv('GOOGLE_ADS_REFRESH_TOKEN') ?: ''));
    if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
        return '';
    }

    $handle = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($handle);
    $errno = curl_errno($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    if ($errno !== 0 || $status !== 200 || !is_string($body)) {
        return '';
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? trim((string)($decoded['access_token'] ?? '')) : '';
}

function svgads_send_approved_purchase(array $order): bool
{
    $customerId = preg_replace('/\D+/', '', (string)(getenv('GOOGLE_ADS_CUSTOMER_ID') ?: '')) ?: '';
    $actionId = preg_replace('/\D+/', '', (string)(getenv('GOOGLE_ADS_PURCHASE_CONVERSION_ACTION_ID') ?: '')) ?: '';
    $developerToken = trim((string)(getenv('GOOGLE_ADS_DEVELOPER_TOKEN') ?: ''));
    if ($customerId === '' || $actionId === '' || $developerToken === '') {
        return false;
    }

    $conversionAction = "customers/{$customerId}/conversionActions/{$actionId}";
    $conversion = svgads_build_click_conversion($order, $conversionAction);
    if ($conversion === null) {
        return false;
    }

    $accessToken = svgads_access_token();
    if ($accessToken === '') {
        error_log('[GoogleAdsPurchase] order=' . ($order['order_number'] ?? '') . ' success=no stage=oauth');
        return false;
    }

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'developer-token: ' . $developerToken,
    ];
    $loginCustomerId = preg_replace('/\D+/', '', (string)(getenv('GOOGLE_ADS_LOGIN_CUSTOMER_ID') ?: '')) ?: '';
    if ($loginCustomerId !== '') {
        $headers[] = 'login-customer-id: ' . $loginCustomerId;
    }

    $version = trim((string)(getenv('GOOGLE_ADS_API_VERSION') ?: 'v22'));
    if (preg_match('/^v\d+$/', $version) !== 1) {
        $version = 'v22';
    }
    $url = "https://googleads.googleapis.com/{$version}/customers/{$customerId}:uploadClickConversions";
    [$status, $errno, $body] = svgads_http_post_json($url, $headers, [
        'conversions' => [$conversion],
        'partialFailure' => true,
    ]);

    $decoded = json_decode($body, true);
    $partialFailure = is_array($decoded) ? ($decoded['partialFailureError'] ?? null) : null;
    $success = $errno === 0 && $status === 200 && empty($partialFailure) && !empty($decoded['results'][0]);
    error_log(
        '[GoogleAdsPurchase] order=' . ($order['order_number'] ?? '')
        . ' success=' . ($success ? 'yes' : 'no')
        . ' status=' . $status
        . ' click_id=' . (array_key_first(array_intersect_key($conversion, array_flip(['gclid','gbraid','wbraid']))) ?: 'none')
    );
    return $success;
}
