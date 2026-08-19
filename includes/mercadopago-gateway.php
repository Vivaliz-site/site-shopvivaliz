<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap-env.php';

final class SvMercadoPagoApiException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $publicCode,
    ) {
        parent::__construct($publicCode, $httpStatus);
    }
}

function svmp_env(string ...$keys): string
{
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

function svmp_base_url(): string
{
    $configured = rtrim(svmp_env('SHOPVIVALIZ_BASE_URL', 'APP_URL', 'SITE_URL'), '/');
    if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_URL) && str_starts_with(strtolower($configured), 'https://')) {
        return $configured;
    }
    return 'https://shopvivaliz.com.br';
}

function svmp_order_number_is_valid(string $orderNumber): bool
{
    return preg_match('/^SV\d{17}$/', $orderNumber) === 1;
}

/** @return list<string> */
function svmp_order_directories(): array
{
    return [
        dirname(__DIR__) . '/storage/orders',
        rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'shopvivaliz-orders',
    ];
}

function svmp_find_order_path(string $orderNumber): string
{
    if (!svmp_order_number_is_valid($orderNumber)) {
        return '';
    }
    foreach (svmp_order_directories() as $directory) {
        $path = $directory . DIRECTORY_SEPARATOR . $orderNumber . '.json';
        if (is_file($path) && is_readable($path)) {
            return $path;
        }
    }
    return '';
}

/** @return array<string,mixed> */
function svmp_read_order(string $path): array
{
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function svmp_session_matches(array $order, string $sessionToken): bool
{
    $expected = (string)($order['payment_session_hash'] ?? '');
    return $expected !== '' && $sessionToken !== '' && hash_equals($expected, hash('sha256', $sessionToken));
}

function svmp_validate_cpf(string $cpf): bool
{
    $digits = preg_replace('/\D+/', '', $cpf) ?? '';
    if (strlen($digits) !== 11 || preg_match('/^(\d)\1{10}$/', $digits) === 1) {
        return false;
    }
    for ($position = 9; $position <= 10; $position++) {
        $sum = 0;
        for ($index = 0; $index < $position; $index++) {
            $sum += ((int)$digits[$index]) * (($position + 1) - $index);
        }
        $digit = ($sum * 10) % 11;
        if ($digit === 10) {
            $digit = 0;
        }
        if ($digit !== (int)$digits[$position]) {
            return false;
        }
    }
    return true;
}

function svmp_validate_cnpj(string $cnpj): bool
{
    $digits = preg_replace('/\D+/', '', $cnpj) ?? '';
    if (strlen($digits) !== 14 || preg_match('/^(\d)\1{13}$/', $digits) === 1) {
        return false;
    }

    $length = 12;
    $numbers = substr($digits, 0, $length);
    $sum = 0;
    $pos = $length - 7;
    for ($i = $length; $i >= 1; $i--) {
        $sum += (int)$numbers[$length - $i] * $pos--;
        if ($pos < 2) {
            $pos = 9;
        }
    }
    $result = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
    if ($result !== (int)$digits[12]) {
        return false;
    }

    $length = 13;
    $numbers = substr($digits, 0, $length);
    $sum = 0;
    $pos = $length - 7;
    for ($i = $length; $i >= 1; $i--) {
        $sum += (int)$numbers[$length - $i] * $pos--;
        if ($pos < 2) {
            $pos = 9;
        }
    }
    $result = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
    return $result === (int)$digits[13];
}

/** @return array{type:string,number:string}|null */
function svmp_identification_data(string $value): ?array
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if ($digits === '') {
        return null;
    }
    if (strlen($digits) === 11 && svmp_validate_cpf($digits)) {
        return ['type' => 'CPF', 'number' => $digits];
    }
    if (strlen($digits) === 14 && svmp_validate_cnpj($digits)) {
        return ['type' => 'CNPJ', 'number' => $digits];
    }
    return null;
}

/** @return array{0:string,1:string} */
function svmp_split_name(string $fullName): array
{
    $parts = preg_split('/\s+/', trim($fullName)) ?: [];
    $firstName = array_shift($parts) ?: 'Cliente';
    $lastName = trim(implode(' ', $parts));
    return [$firstName, $lastName !== '' ? $lastName : 'Vivaliz'];
}

function svmp_money(float $amount): string
{
    return number_format(round($amount, 2), 2, '.', '');
}

/** @return array{area_code:string,number:string} */
function svmp_phone_parts(string $phone): array
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) >= 11) {
        return [
            'area_code' => substr($digits, 0, 2),
            'number' => substr($digits, 2),
        ];
    }
    if (strlen($digits) >= 10) {
        return [
            'area_code' => substr($digits, 0, 2),
            'number' => substr($digits, 2),
        ];
    }
    if ($digits !== '') {
        return [
            'area_code' => '37',
            'number' => $digits,
        ];
    }
    return [
        'area_code' => '37',
        'number' => '000000000',
    ];
}

function svmp_truncate(string $value, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
}

function svmp_state_name(string $state): string
{
    $uf = strtoupper(trim($state));
    $names = [
        'AC' => 'Acre',
        'AL' => 'Alagoas',
        'AP' => 'Amapá',
        'AM' => 'Amazonas',
        'BA' => 'Bahia',
        'CE' => 'Ceará',
        'DF' => 'Distrito Federal',
        'ES' => 'Espírito Santo',
        'GO' => 'Goiás',
        'MA' => 'Maranhão',
        'MT' => 'Mato Grosso',
        'MS' => 'Mato Grosso do Sul',
        'MG' => 'Minas Gerais',
        'PA' => 'Pará',
        'PB' => 'Paraíba',
        'PR' => 'Paraná',
        'PE' => 'Pernambuco',
        'PI' => 'Piauí',
        'RJ' => 'Rio de Janeiro',
        'RN' => 'Rio Grande do Norte',
        'RS' => 'Rio Grande do Sul',
        'RO' => 'Rondônia',
        'RR' => 'Roraima',
        'SC' => 'Santa Catarina',
        'SP' => 'São Paulo',
        'SE' => 'Sergipe',
        'TO' => 'Tocantins',
    ];
    return $names[$uf] ?? $state;
}

/**
 * @return array{status:int, raw:string, transport_error:bool}
 */
function svmp_python_request(string $method, string $path, string $accessToken, array $headers, string $payload): array
{
    $python = svmp_env('PYTHON_BINARY');
    if ($python === '') {
        $python = 'python';
    }

    $script = <<<'PY'
import json
import sys

import requests

method = sys.argv[1]
url = sys.argv[2]
headers_path = sys.argv[3]
body_path = sys.argv[4] if len(sys.argv) > 4 else ""

with open(headers_path, "r", encoding="utf-8") as fh:
    headers = json.load(fh)

body = ""
if body_path:
    with open(body_path, "r", encoding="utf-8") as fh:
        body = fh.read()

kwargs = {"headers": headers, "timeout": 20}
if body:
    kwargs["data"] = body.encode("utf-8")

try:
    response = requests.request(method, url, **kwargs)
    print(json.dumps({
        "status": response.status_code,
        "raw": response.text,
    }, ensure_ascii=False))
except Exception as exc:
    print(json.dumps({
        "error": str(exc),
    }, ensure_ascii=False))
PY;

    $tempDir = sys_get_temp_dir();
    $scriptFile = tempnam($tempDir, 'svmp_py_');
    if ($scriptFile === false) {
        return ['status' => 0, 'raw' => '', 'transport_error' => true];
    }
    $scriptPath = $scriptFile . '.py';
    @rename($scriptFile, $scriptPath);
    if (@file_put_contents($scriptPath, $script) === false) {
        @unlink($scriptPath);
        return ['status' => 0, 'raw' => '', 'transport_error' => true];
    }

    $headersFile = tempnam($tempDir, 'svmp_hdr_');
    $bodyFile = tempnam($tempDir, 'svmp_bdy_');
    if ($headersFile === false || $bodyFile === false) {
        @unlink($scriptPath);
        if (is_string($headersFile)) {
            @unlink($headersFile);
        }
        if (is_string($bodyFile)) {
            @unlink($bodyFile);
        }
        return ['status' => 0, 'raw' => '', 'transport_error' => true];
    }
    if (@file_put_contents($headersFile, json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
        @unlink($scriptPath);
        @unlink($headersFile);
        @unlink($bodyFile);
        return ['status' => 0, 'raw' => '', 'transport_error' => true];
    }
    if (@file_put_contents($bodyFile, $payload) === false) {
        @unlink($scriptPath);
        @unlink($headersFile);
        @unlink($bodyFile);
        return ['status' => 0, 'raw' => '', 'transport_error' => true];
    }

    $command = escapeshellarg($python)
        . ' ' . escapeshellarg($scriptPath)
        . ' ' . escapeshellarg(strtoupper($method))
        . ' ' . escapeshellarg('https://api.mercadopago.com' . $path)
        . ' ' . escapeshellarg($headersFile)
        . ' ' . escapeshellarg($bodyFile);

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($command, $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        @unlink($scriptPath);
        return ['status' => 0, 'raw' => '', 'transport_error' => true];
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errorOutput = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    @unlink($scriptPath);
    @unlink($headersFile);
    @unlink($bodyFile);
    if (!is_string($output) || trim($output) === '' || ($exitCode !== 0 && trim((string)$errorOutput) !== '')) {
        return ['status' => 0, 'raw' => '', 'transport_error' => true];
    }

    $decoded = json_decode(trim($output), true);
    if (!is_array($decoded) || isset($decoded['error'])) {
        return ['status' => 0, 'raw' => '', 'transport_error' => true];
    }

    return [
        'status' => (int)($decoded['status'] ?? 0),
        'raw' => (string)($decoded['raw'] ?? ''),
        'transport_error' => false,
    ];
}


/** @return array */
function svmp_build_items(array $order): array
{
    $items = [];
    foreach ((array)($order['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $quantity = max(1, (int)($item['quantity'] ?? 1));
        $unitPrice = round((float)($item['price'] ?? 0), 2);
        if ($unitPrice <= 0) {
            continue;
        }
        $items[] = [
            'id' => svmp_truncate((string)($item['sku'] ?? 'produto'), 50),
            'title' => svmp_truncate((string)($item['name'] ?? $item['sku'] ?? 'Produto ShopVivaliz'), 120),
            'quantity' => $quantity,
            'currency_id' => 'BRL',
            'unit_price' => $unitPrice,
            'category_id' => 'others',
            'external_code' => svmp_truncate((string)($item['sku'] ?? ''), 50),
        ];
    }
    $shipping = round((float)($order['shipping_total'] ?? 0), 2);
    if ($shipping > 0) {
        $items[] = [
            'id' => 'frete',
            'title' => svmp_truncate((string)($order['shipping_label'] ?? 'Frete'), 120),
            'quantity' => 1,
            'currency_id' => 'BRL',
            'unit_price' => $shipping,
            'category_id' => 'others',
            'external_code' => 'shipping_fee',
        ];
    }
    $discount = round((float)($order['coupon_discount'] ?? 0), 2);
    if ($discount > 0) {
        $couponCode = (string)($order['coupon_code'] ?? '');
        $items[] = [
            'id' => 'desconto',
            'title' => svmp_truncate('Desconto' . ($couponCode !== '' ? ' (' . $couponCode . ')' : ''), 120),
            'quantity' => 1,
            'currency_id' => 'BRL',
            'unit_price' => -$discount,
            'category_id' => 'others',
            'external_code' => 'coupon_discount',
        ];
    }
    return $items;
}

/** @return array<string,mixed> */
function svmp_boleto_payload(array $order): array
{
    $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
    [$firstName, $lastName] = svmp_split_name((string)($customer['name'] ?? ''));
    $cpf = preg_replace('/\D+/', '', (string)($customer['cpf'] ?? '')) ?? '';
    $total = round((float)($order['total'] ?? 0), 2);
    if ($total <= 0 || !svmp_validate_cpf($cpf)) {
        throw new InvalidArgumentException('invalid_boleto_order');
    }

    $required = ['email', 'cep', 'street_name', 'street_number', 'neighborhood', 'city', 'state'];
    foreach ($required as $field) {
        if (trim((string)($customer[$field] ?? '')) === '') {
            throw new InvalidArgumentException('missing_boleto_payer_fields');
        }
    }

    $baseUrl = svmp_base_url();
    $stateUf = strtoupper((string)($customer['state'] ?? ''));
    $stateName = svmp_state_name($stateUf);
    $statementDescriptor = strtoupper(svmp_truncate((string)($order['statement_descriptor'] ?? 'SHOPVIVALIZ'), 13));
    $registrationDate = (string)($customer['registration_date'] ?? $order['customer_registration_date'] ?? $order['created_at'] ?? date('c'));
    $lastPurchase = (string)($customer['last_purchase'] ?? $order['last_purchase'] ?? '');
    $customerId = trim((string)($customer['customer_id'] ?? $order['customer_id'] ?? ''));
    $phone = svmp_phone_parts((string)($customer['phone'] ?? ''));
    $streetName = (string)($customer['street_name'] ?? $customer['address'] ?? '');
    $streetNumber = (string)($customer['street_number'] ?? 'SN');
    $zipCode = preg_replace('/\D+/', '', (string)($customer['cep'] ?? '')) ?? '';
    $cityName = (string)($customer['city'] ?? '');
    $isPriorityShipping = (bool)($order['priority_shipping'] ?? $order['express_shipping'] ?? false);

    $payload = [
        'type' => 'online',
        'external_reference' => (string)($order['order_number'] ?? ''),
        'processing_mode' => 'automatic',
        'total_amount' => svmp_money($total),
        'description' => 'Pedido ShopVivaliz ' . (string)($order['order_number'] ?? ''),
        'items' => svmp_build_items($order),
        'payer' => [
            'email' => (string)$customer['email'],
            'first_name' => $firstName,
            'last_name' => $lastName,
            'identification' => ['type' => 'CPF', 'number' => $cpf],
            'address' => [
                'street_name' => (string)$customer['street_name'],
                'street_number' => (string)$customer['street_number'],
                'zip_code' => preg_replace('/\D+/', '', (string)$customer['cep']),
                'neighborhood' => (string)$customer['neighborhood'],
                'state' => svmp_state_name((string)$customer['state']),
                'city' => (string)$customer['city'],
            ],
        ],
        'transactions' => [
            'payments' => [[
                'amount' => svmp_money($total),
                'payment_method' => [
                    'id' => 'boleto',
                    'type' => 'ticket',
                    'statement_descriptor' => $statementDescriptor !== '' ? $statementDescriptor : 'SHOPVIVALIZ',
                ],
            ]],
        ],
        'notification_url' => $baseUrl . '/api/webhook-mercadopago.php?source_news=webhooks',
        'statement_descriptor' => $statementDescriptor !== '' ? $statementDescriptor : 'SHOPVIVALIZ',
        'additional_info' => [
            'payer' => [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'registration_date' => $registrationDate,
                'last_purchase' => $lastPurchase !== '' ? $lastPurchase : null,
                'authentication_type' => 'email',
                'is_first_purchase_online' => (bool)($order['is_first_purchase_online'] ?? true),
                'phone' => $phone,
                'customer_id' => $customerId !== '' ? $customerId : null,
            ],
            'shipments' => [
                'express_shipments' => $isPriorityShipping,
                'receiver_address' => [
                    'zip_code' => $zipCode,
                    'state_name' => $stateName,
                    'city_name' => $cityName,
                    'street_number' => $streetNumber,
                    'street_name' => $streetName,
                    'neighborhood' => (string)($customer['neighborhood'] ?? ''),
                ],
                'receivers_address' => [
                    'zip_code' => $zipCode,
                    'state_name' => $stateName,
                    'city_name' => $cityName,
                    'street_number' => $streetNumber,
                    'street_name' => $streetName,
                    'neighborhood' => (string)($customer['neighborhood'] ?? ''),
                ],
            ],
        ],
        'shipment' => [
            'address' => [
                'zip_code' => $zipCode,
                'state' => $stateUf !== '' ? $stateUf : $stateName,
                'city' => $cityName,
                'street_number' => $streetNumber,
                'street_name' => $streetName,
                'neighborhood' => (string)($customer['neighborhood'] ?? ''),
            ],
        ],
    ];

    if ($payload['additional_info']['payer']['last_purchase'] === null) {
        unset($payload['additional_info']['payer']['last_purchase']);
    }
    if ($payload['additional_info']['payer']['customer_id'] === null) {
        unset($payload['additional_info']['payer']['customer_id']);
    }

    return $payload;
}

/** @return array<string,mixed> */
function svmp_preference_payload(array $order): array
{
    $items = svmp_build_items($order);
    if ($items === []) {
        throw new InvalidArgumentException('invalid_preference_items');
    }

    $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
    [$firstName, $lastName] = svmp_split_name((string)($customer['name'] ?? ''));
    $baseUrl = svmp_base_url();
    $orderNumber = (string)($order['order_number'] ?? '');
    $document = preg_replace('/\D+/', '', (string)($customer['cpf'] ?? '')) ?? '';
    $identification = svmp_identification_data($document);
    $stateUf = strtoupper((string)($customer['state'] ?? ''));
    $stateName = svmp_state_name($stateUf);
    $statementDescriptor = strtoupper(svmp_truncate((string)($order['statement_descriptor'] ?? 'SHOPVIVALIZ'), 13));
    $registrationDate = (string)($customer['registration_date'] ?? $order['customer_registration_date'] ?? $order['created_at'] ?? date('c'));
    $lastPurchase = (string)($customer['last_purchase'] ?? $order['last_purchase'] ?? '');
    $customerId = trim((string)($customer['customer_id'] ?? $order['customer_id'] ?? ''));
    $isFirstPurchaseOnline = array_key_exists('is_first_purchase_online', $customer)
        ? (bool)$customer['is_first_purchase_online']
        : (bool)($order['is_first_purchase_online'] ?? true);
    $phone = svmp_phone_parts((string)($customer['phone'] ?? ''));
    $streetName = (string)($customer['street_name'] ?? $customer['address'] ?? '');
    $streetNumber = (string)($customer['street_number'] ?? 'SN');
    $zipCode = preg_replace('/\D+/', '', (string)($customer['cep'] ?? '')) ?? '';
    $cityName = (string)($customer['city'] ?? '');
    $isPriorityShipping = (bool)($order['priority_shipping'] ?? $order['express_shipping'] ?? false);

    $payload = [
        'items' => $items,
        'payer' => [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $firstName,
            'surname' => $lastName,
            'email' => (string)($customer['email'] ?? ''),
            'phone' => $phone,
            'identification' => $identification,
            'customer_id' => $customerId !== '' ? $customerId : null,
            'address' => [
                'street_name' => $streetName,
                'street_number' => $streetNumber,
                'zip_code' => $zipCode,
                'neighborhood' => (string)($customer['neighborhood'] ?? ''),
                'state' => $stateUf !== '' ? $stateUf : $stateName,
                'city' => $cityName,
            ],
        ],
        'external_reference' => $orderNumber,
        'statement_descriptor' => $statementDescriptor !== '' ? $statementDescriptor : 'SHOPVIVALIZ',
        'back_urls' => [
            'success' => $baseUrl . '/checkout/retorno?result=success',
            'pending' => $baseUrl . '/checkout/retorno?result=pending',
            'failure' => $baseUrl . '/checkout/retorno?result=failure',
        ],
        'auto_return' => 'approved',
        'notification_url' => $baseUrl . '/api/webhook-mercadopago.php?source_news=webhooks',
        'metadata' => ['shopvivaliz_order' => $orderNumber],
        'additional_info' => [
            'payer' => [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'registration_date' => $registrationDate,
                'last_purchase' => $lastPurchase !== '' ? $lastPurchase : null,
                'authentication_type' => 'email',
                'is_first_purchase_online' => $isFirstPurchaseOnline,
                'phone' => $phone,
                'customer_id' => $customerId !== '' ? $customerId : null,
            ],
            'shipments' => [
                'express_shipments' => $isPriorityShipping,
                'receiver_address' => [
                    'zip_code' => $zipCode,
                    'state_name' => $stateName,
                    'city_name' => $cityName,
                    'street_number' => $streetNumber,
                    'street_name' => $streetName,
                    'neighborhood' => (string)($customer['neighborhood'] ?? ''),
                ],
                'receivers_address' => [
                    'zip_code' => $zipCode,
                    'state_name' => $stateName,
                    'city_name' => $cityName,
                    'street_number' => $streetNumber,
                    'street_name' => $streetName,
                    'neighborhood' => (string)($customer['neighborhood'] ?? ''),
                ],
            ]
        ]
    ];

    if ($payload['payer']['identification'] === null) {
        unset($payload['payer']['identification']);
    }
    if ($payload['payer']['customer_id'] === null) {
        unset($payload['payer']['customer_id']);
    }
    if ($payload['additional_info']['payer']['last_purchase'] === null) {
        unset($payload['additional_info']['payer']['last_purchase']);
    }
    if ($payload['additional_info']['payer']['customer_id'] === null) {
        unset($payload['additional_info']['payer']['customer_id']);
    }

    return $payload;
}

/** @return array<string,mixed> */
function svmp_api_request(string $method, string $path, string $accessToken, ?array $payload = null, string $idempotencyKey = '', string $deviceId = ''): array
{
    if ($accessToken === '') {
        throw new SvMercadoPagoApiException(503, 'gateway_unavailable');
    }
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken,
        'User-Agent: ShopVivaliz/9.2.104',
    ];
    if ($idempotencyKey !== '') {
        $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
    }
    if ($deviceId !== '') {
        $headers[] = 'X-meli-session-id: ' . $deviceId;
    }

    $encodedPayload = $payload !== null
        ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        : '';
    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.mercadopago.com' . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 7,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedPayload);
        }
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $transportError = curl_errno($ch) !== 0;
        curl_close($ch);
    } else {
        $context = stream_context_create(['http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $encodedPayload,
            'timeout' => 20,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents('https://api.mercadopago.com' . $path, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
                $status = (int)$matches[1];
            }
        }
        $transportError = $raw === false;
    }
    if (($raw === false || $transportError) && function_exists('shell_exec')) {
        $fallback = svmp_python_request($method, $path, $accessToken, $headers, $encodedPayload);
        if (!$fallback['transport_error']) {
            $raw = $fallback['raw'];
            $status = $fallback['status'];
            $transportError = false;
        }
    }
    if ($raw === false || $transportError) {
        throw new SvMercadoPagoApiException(502, 'gateway_network_error');
    }

    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        throw new SvMercadoPagoApiException(502, 'gateway_invalid_response');
    }
    if ($status < 200 || $status >= 300) {
        $candidate = (string)($data['code'] ?? $data['error'] ?? 'gateway_rejected_request');
        $publicCode = preg_match('/^[a-zA-Z0-9_.-]{1,80}$/', $candidate) === 1 ? strtolower($candidate) : 'gateway_rejected_request';
        throw new SvMercadoPagoApiException($status >= 400 && $status < 500 ? 422 : 502, $publicCode);
    }
    return $data;
}

/** @return array<string,mixed> */
function svmp_create_boleto(array $order, string $accessToken): array
{
    $payload = svmp_boleto_payload($order);
    $orderNumber = (string)($order['order_number'] ?? '');
    $idempotencyKey = hash_hmac('sha256', 'boleto|' . $orderNumber, $accessToken);
    $deviceId = (string)($order['device_id'] ?? '');
    $response = svmp_api_request('POST', '/v1/orders', $accessToken, $payload, $idempotencyKey, $deviceId);
    $payment = $response['transactions']['payments'][0] ?? [];
    $paymentMethod = is_array($payment) && is_array($payment['payment_method'] ?? null) ? $payment['payment_method'] : [];
    $ticketUrl = (string)($paymentMethod['ticket_url'] ?? '');
    if ((string)($response['id'] ?? '') === '' || $ticketUrl === '') {
        throw new SvMercadoPagoApiException(502, 'boleto_missing_ticket');
    }
    return [
        'order_id' => (string)$response['id'],
        'payment_id' => (string)($payment['id'] ?? ''),
        'status' => (string)($payment['status'] ?? $response['status'] ?? 'action_required'),
        'status_detail' => (string)($payment['status_detail'] ?? $response['status_detail'] ?? 'waiting_payment'),
        'ticket_url' => $ticketUrl,
        'digitable_line' => (string)($paymentMethod['digitable_line'] ?? ''),
        'barcode_content' => (string)($paymentMethod['barcode_content'] ?? ''),
    ];
}

/** @return array<string,mixed> */
function svmp_create_preference(array $order, string $accessToken): array
{
    $payload = svmp_preference_payload($order);
    $orderNumber = (string)($order['order_number'] ?? '');
    $idempotencyKey = hash_hmac('sha256', 'preference|' . $orderNumber, $accessToken);
    $deviceId = (string)($order['device_id'] ?? '');
    $response = svmp_api_request('POST', '/checkout/preferences', $accessToken, $payload, $idempotencyKey, $deviceId);
    $checkoutUrl = (string)($response['init_point'] ?? '');
    if ((string)($response['id'] ?? '') === '' || !str_starts_with($checkoutUrl, 'https://')) {
        throw new SvMercadoPagoApiException(502, 'preference_missing_checkout_url');
    }
    return [
        'preference_id' => (string)$response['id'],
        'checkout_url' => $checkoutUrl,
    ];
}

/** @return array{ts:string,v1:string} */
function svmp_signature_parts(string $signature): array
{
    $parts = ['ts' => '', 'v1' => ''];
    foreach (explode(',', $signature) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        if (array_key_exists($key, $parts)) {
            $parts[$key] = trim($value);
        }
    }
    return $parts;
}

function svmp_validate_webhook_signature(string $signature, string $requestId, string $dataId, string $secret): bool
{
    if ($signature === '' || $requestId === '' || $dataId === '' || $secret === '') {
        return false;
    }
    $parts = svmp_signature_parts($signature);
    if ($parts['ts'] === '' || preg_match('/^[a-f0-9]{64}$/i', $parts['v1']) !== 1) {
        return false;
    }
    $manifest = 'id:' . strtolower($dataId) . ';request-id:' . $requestId . ';ts:' . $parts['ts'] . ';';
    return hash_equals(strtolower($parts['v1']), hash_hmac('sha256', $manifest, $secret));
}

function svmp_local_status(string $providerStatus): string
{
    return match (strtolower($providerStatus)) {
        'approved', 'processed' => 'payment_approved',
        'action_required', 'pending', 'in_process', 'processing' => 'payment_pending',
        'refunded' => 'payment_refunded',
        'charged_back' => 'payment_chargeback',
        'cancelled', 'canceled', 'expired' => 'payment_cancelled',
        'rejected', 'failed' => 'payment_failed',
        default => 'payment_pending',
    };
}

// Rodada 9 (2026-08-19) -- correcao do achado R9-1/R9-2: estas duas funcoes
// viviam em api/webhook-mercadopago.php (definidas no topo do arquivo, fora
// do bloco morto). Precisam ser chamadas tambem por
// includes/webhook-job-dispatcher.php (o worker de fila), que NAO pode dar
// require no endpoint HTTP inteiro -- isso executaria o codigo de tratamento
// de requisicao do topo do arquivo (que le php://input, checa METHOD, etc)
// fora de um contexto HTTP real e derrubaria o worker. Movidas pra ca, include
// compartilhado por ambos. Ver docs/AGENTS.md, entrada da Rodada 9.
function svmp_webhook_extract_boleto(array $payment, array $paymentDetail = []): array
{
    $details = is_array($payment['transaction_details'] ?? null) ? $payment['transaction_details'] : [];
    $detailDetails = is_array($paymentDetail['transaction_details'] ?? null) ? $paymentDetail['transaction_details'] : [];
    $merged = array_replace($details, $detailDetails);

    $ticketUrl = trim((string)(
        $merged['external_resource_url']
        ?? $merged['ticket_url']
        ?? $payment['external_resource_url']
        ?? $paymentDetail['external_resource_url']
        ?? ''
    ));
    $digitableLine = trim((string)(
        $merged['digitable_line']
        ?? $merged['line']
        ?? $merged['payment_method_reference_id']
        ?? $payment['barcode']['content']
        ?? $paymentDetail['barcode']['content']
        ?? ''
    ));
    $barcodeContent = trim((string)(
        $merged['barcode_content']
        ?? $merged['barcode']
        ?? $payment['barcode']['content']
        ?? $paymentDetail['barcode']['content']
        ?? ''
    ));

    return [
        'ticket_url' => $ticketUrl,
        'digitable_line' => $digitableLine,
        'barcode_content' => $barcodeContent,
    ];
}

function svmp_webhook_send_boleto_email(array $order): bool
{
    try {
        return svem_send_order_email($order, 'boleto_generated');
    } catch (Throwable $e) {
        error_log('[MercadoPago] boleto webhook email failure: order=' . ($order['order_number'] ?? 'N/A') . ' ' . $e->getMessage());
        return false;
    }
}
