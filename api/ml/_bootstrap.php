<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/constants.php';

function ml_env(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

function ml_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function ml_mask(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    $len = strlen($value);
    if ($len <= 8) {
        return str_repeat('*', $len);
    }
    return substr($value, 0, 4) . str_repeat('*', max(0, $len - 8)) . substr($value, -4);
}

function ml_token_path(): string
{
    return ml_env('ML_TOKEN_FILE') ?: BASE_PATH . '/data/tokens.json';
}

function ml_load_tokens(): array
{
    $path = ml_token_path();
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function ml_save_tokens(array $tokens): void
{
    $path = ml_token_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }

    $payload = json_encode($tokens, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($payload === false || file_put_contents($path, $payload, LOCK_EX) === false) {
        ml_json(['ok' => false, 'error' => 'token_write_failed'], 500);
    }
}

function ml_access_token(): ?string
{
    $envToken = ml_env('ML_ACCESS_TOKEN');
    if ($envToken) {
        return $envToken;
    }

    $tokens = ml_load_tokens();
    return isset($tokens['access_token']) && is_string($tokens['access_token']) ? $tokens['access_token'] : null;
}

function ml_refresh_token(): ?string
{
    $envToken = ml_env('ML_REFRESH_TOKEN');
    if ($envToken) {
        return $envToken;
    }

    $tokens = ml_load_tokens();
    return isset($tokens['refresh_token']) && is_string($tokens['refresh_token']) ? $tokens['refresh_token'] : null;
}

function ml_api_request(string $method, string $path, ?array $body = null, array $query = []): array
{
    $token = ml_access_token();
    if (!$token) {
        return [
            'ok' => false,
            'status' => 401,
            'data' => ['error' => 'missing_access_token'],
        ];
    }

    $base = rtrim(ml_env('ML_API_BASE', 'https://api.mercadolibre.com') ?? 'https://api.mercadolibre.com', '/');
    $url = $base . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return [
            'ok' => false,
            'status' => 500,
            'data' => ['error' => 'curl_error', 'message' => $error],
        ];
    }

    $decoded = json_decode($raw, true);
    $data = is_array($decoded) ? $decoded : ['raw' => $raw];

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'data' => $data,
    ];
}
