<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap-env.php';
require_once dirname(__DIR__) . '/includes/product-price-enrich.php';

final class SvInfinitePayApiException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $publicCode,
        public readonly array $details = [],
    ) {
        parent::__construct($publicCode, $httpStatus);
    }
}

function svip_env(string ...$keys): string
{
    svp_env_load();
    foreach ($keys as $key) {
        $value = getenv($key);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && trim($_ENV[$key]) !== '') {
            return trim($_ENV[$key]);
        }
    }
    return '';
}

function svip_base_url(): string
{
    $configured = rtrim(svip_env('SHOPVIVALIZ_BASE_URL', 'APP_URL', 'SITE_URL'), '/');
    if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_URL) && str_starts_with(strtolower($configured), 'https://')) {
        return $configured;
    }
    return 'https://shopvivaliz.com.br';
}

function svip_handle(): string
{
    return svip_env('INFINITEPAY_HANDLE') !== '' ? svip_env('INFINITEPAY_HANDLE') : 'frederico-castro-ap4';
}

function svip_money_to_cents(float $amount): int
{
    return (int)round(max(0, $amount) * 100);
}

function svip_phone_digits(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits === '') {
        return '';
    }
    return str_starts_with($digits, '55') ? $digits : '55' . $digits;
}

/** @return array<string,mixed> */
function svip_link_payload(array $order): array
{
    $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
    $items = [];
    foreach ((array)($order['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $description = trim((string)($item['name'] ?? $item['sku'] ?? 'Produto ShopVivaliz'));
        $quantity = max(1, (int)($item['quantity'] ?? 1));
        $priceCents = svip_money_to_cents((float)($item['price'] ?? 0));
        if ($description === '' || $priceCents <= 0) {
            continue;
        }
        $items[] = [
            'description' => $description,
            'quantity' => $quantity,
            'price' => $priceCents,
        ];
    }

    $shippingTotal = (float)($order['shipping_total'] ?? 0);
    if ($shippingTotal > 0) {
        $items[] = [
            'description' => trim((string)($order['shipping_label'] ?? 'Frete')) ?: 'Frete',
            'quantity' => 1,
            'price' => svip_money_to_cents($shippingTotal),
        ];
    }

    $couponDiscount = (float)($order['coupon_discount'] ?? 0);
    if ($couponDiscount > 0) {
        $items[] = [
            'description' => 'Desconto',
            'quantity' => 1,
            'price' => -svip_money_to_cents($couponDiscount),
        ];
    }

    if ($items === []) {
        throw new InvalidArgumentException('invalid_infinitepay_items');
    }

    $baseUrl = svip_base_url();
    $orderNumber = (string)($order['order_number'] ?? '');

    return [
        'handle' => svip_handle(),
        'items' => $items,
        'order_nsu' => $orderNumber,
        'redirect_url' => $baseUrl . '/checkout-return.php?gateway=infinitepay&result=approved',
        'webhook_url' => $baseUrl . '/api/webhook-infinitepay.php',
        'customer' => [
            'name' => (string)($customer['name'] ?? ''),
            'email' => (string)($customer['email'] ?? ''),
            'phone' => svip_phone_digits((string)($customer['phone'] ?? '')),
        ],
        'address' => [
            'street' => (string)($customer['street_name'] ?? $customer['address'] ?? ''),
            'number' => (string)($customer['street_number'] ?? ''),
            'complement' => (string)($customer['complement'] ?? ''),
            'district' => (string)($customer['neighborhood'] ?? ''),
            'city' => (string)($customer['city'] ?? ''),
            'state' => (string)($customer['state'] ?? ''),
            'zipcode' => preg_replace('/\D+/', '', (string)($customer['cep'] ?? '')) ?? '',
        ],
    ];
}

/** @return array<string,mixed> */
function svip_api_request(string $method, string $path, ?array $payload = null): array
{
    $url = 'https://api.checkout.infinitepay.io' . $path;
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'User-Agent: ShopVivaliz/9.2.104',
    ];
    $encodedPayload = $payload !== null
        ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        : '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $encodedPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new SvInfinitePayApiException(502, 'gateway_network_error');
        }
        if ($status < 200 || $status >= 300) {
            $decodedError = json_decode((string)$raw, true);
            throw new SvInfinitePayApiException(
                $status >= 400 && $status < 500 ? 422 : 502,
                'gateway_rejected_request',
                is_array($decodedError) ? $decodedError : ['raw' => $raw, 'curl_error' => $curlError]
            );
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            throw new SvInfinitePayApiException(502, 'gateway_invalid_response');
        }
        return $decoded;
    }

    $context = stream_context_create([
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'content' => $encodedPayload,
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match) === 1) {
        $status = (int)$match[1];
    }
    if ($raw === false) {
        throw new SvInfinitePayApiException(502, 'gateway_network_error');
    }
    $decoded = json_decode((string)$raw, true);
    if ($status < 200 || $status >= 300) {
        throw new SvInfinitePayApiException(
            $status >= 400 && $status < 500 ? 422 : 502,
            'gateway_rejected_request',
            is_array($decoded) ? $decoded : ['raw' => $raw]
        );
    }
    if (!is_array($decoded)) {
        throw new SvInfinitePayApiException(502, 'gateway_invalid_response');
    }
    return $decoded;
}

/** @return array{checkout_url:string,slug:string,raw:array<string,mixed>} */
function svip_create_link(array $order): array
{
    $payload = svip_link_payload($order);
    $response = svip_api_request('POST', '/links', $payload);

    $checkoutUrl = trim((string)($response['url'] ?? $response['checkout_url'] ?? $response['payment_url'] ?? $response['link'] ?? ''));
    $slug = trim((string)($response['slug'] ?? ''));
    if ($checkoutUrl === '') {
        throw new SvInfinitePayApiException(502, 'checkout_url_missing', $response);
    }

    return [
        'checkout_url' => $checkoutUrl,
        'slug' => $slug,
        'raw' => $response,
    ];
}
