<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function svpg_root(): string
{
    return dirname(__DIR__, 2);
}

function svpg_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function svpg_env_load(): void
{
    $path = svpg_root() . '/.env';
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function svpg_env(string ...$keys): string
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

function svpg_mask(?string $value): ?string
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

function svpg_basic_auth(string $secretKey): string
{
    return 'Basic ' . base64_encode($secretKey . ':x');
}

function svpg_request(string $url, string $secretKey): array
{
    $headers = array(
        'Accept: application/json',
        'Authorization: ' . svpg_basic_auth($secretKey),
        'User-Agent: ShopVivaliz Pagarme Diagnostic/1.0',
    );

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
        ));
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'ignore_errors' => true,
                'timeout' => 25,
                'header' => implode("\r\n", $headers),
            ),
            'ssl' => array(
                'verify_peer' => true,
                'verify_peer_name' => true,
            ),
        ));
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? array() as $line) {
            if (preg_match('/\s(\d{3})\s/', $line, $matches)) {
                $status = (int)$matches[1];
                break;
            }
        }
        $error = $body === false ? 'stream_request_failed' : '';
    }

    $decoded = null;
    if (is_string($body) && $body !== '') {
        $decoded = json_decode($body, true);
    }

    return array(
        'ok' => $status >= 200 && $status < 300,
        'status' => $status > 0 ? $status : 502,
        'error' => $error ?: null,
        'body' => is_array($decoded) ? $decoded : $body,
    );
}

svpg_env_load();

$secretKey = svpg_env(
    'PAGARME_SECRET_KEY',
    'PAGARME_API_KEY',
    'SHOPVIVALIZ_PAGARME_SECRET_KEY',
    'SHOPVIVALIZ_PAGARME_API_KEY'
);
$publicKey = svpg_env(
    'PAGARME_PUBLIC_KEY',
    'SHOPVIVALIZ_PAGARME_PUBLIC_KEY'
);
$mode = str_starts_with($secretKey, 'sk_test') ? 'test' : 'live';
$baseUrl = $mode === 'test'
    ? 'https://sdx-api.pagar.me/core/v5'
    : 'https://api.pagar.me/core/v5';

if ($secretKey === '') {
    svpg_json(503, array(
        'ok' => false,
        'agent' => 'pagarme_diagnostic',
        'generated_at' => date('c'),
        'readiness' => 'missing_secret_key',
        'token_detected' => false,
        'secret_key_mask' => null,
        'public_key_detected' => $publicKey !== '',
        'message' => 'Configure PAGARME_SECRET_KEY ou PAGARME_API_KEY no servidor.',
    ));
}

$url = $baseUrl . '/paymentlinks?per_page=1&page=1';
$result = svpg_request($url, $secretKey);

svpg_json($result['status'], array(
    'ok' => $result['ok'],
    'agent' => 'pagarme_diagnostic',
    'generated_at' => date('c'),
    'readiness' => $result['ok'] ? 'operational' : ($result['error'] ?? 'attention'),
    'mode' => $mode,
    'token_detected' => true,
    'secret_key_mask' => svpg_mask($secretKey),
    'public_key_detected' => $publicKey !== '',
    'endpoint' => $url,
    'result' => $result,
));
