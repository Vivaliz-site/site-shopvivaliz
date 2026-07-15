<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function sv_me_env(string $name): ?string
{
    $value = getenv($name);
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }
    return null;
}

function sv_me_mask(?string $value): ?string
{
    if (!$value) {
        return null;
    }
    $len = strlen($value);
    if ($len <= 8) {
        return str_repeat('*', $len);
    }
    return substr($value, 0, 4) . str_repeat('*', max(0, $len - 8)) . substr($value, -4);
}

function sv_me_request(string $url, array $payload, ?string $token): array
{
    if (!$token) {
        return array(
            'ok' => false,
            'status' => 0,
            'error' => 'missing_access_token',
            'message' => 'Configure MELHORENVIO_ACCESS_TOKEN ou SHOPVIVALIZ_MELHORENVIO_ACCESS_TOKEN no servidor.'
        );
    }

    if (!function_exists('curl_init')) {
        return array('ok' => false, 'status' => 0, 'error' => 'curl_missing');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'User-Agent: ShopVivaliz Dev Shipping Diagnostic'
        ),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 25,
    ));
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $decoded = null;
    if (is_string($body) && $body !== '') {
        $decoded = json_decode($body, true);
    }

    return array(
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'curl_error' => $error ?: null,
        'body' => $decoded !== null ? $decoded : $body,
    );
}

$postalCode = preg_replace('/\D+/', '', (string)($_GET['cep'] ?? $_POST['cep'] ?? '35500025'));

$token = sv_me_env('MELHORENVIO_ACCESS_TOKEN') ?: sv_me_env('SHOPVIVALIZ_MELHORENVIO_ACCESS_TOKEN');
$fromPostalCode = preg_replace('/\D+/', '', (string)(sv_me_env('MELHORENVIO_FROM_POSTAL_CODE') ?: sv_me_env('SHOPVIVALIZ_FROM_POSTAL_CODE') ?: '35500000'));

$payload = array(
    'from' => array('postal_code' => $fromPostalCode),
    'to' => array('postal_code' => $postalCode),
    'products' => array(
        array(
            'id' => 'diag-1',
            'width' => 35,
            'height' => 45,
            'length' => 34,
            'weight' => 3.5,
            'insurance_value' => 578.76,
            'quantity' => 1,
        )
    ),
    'options' => array(
        'receipt' => false,
        'own_hand' => false,
        'collect' => false,
    )
);

$url = 'https://www.melhorenvio.com.brapi/v2/me/shipment/calculate';
$result = sv_me_request($url, $payload, $token);

http_response_code($result['ok'] ? 200 : 502);
echo json_encode(array(
    'ok' => $result['ok'],
    'agent' => 'melhorenvio_diagnostic',
    'generated_at' => date('c'),
    'cep' => $postalCode,
    'token_detected' => (bool)$token,
    'token_mask' => sv_me_mask($token),
    'from_postal_code' => $fromPostalCode,
    'payload' => $payload,
    'result' => $result,
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
