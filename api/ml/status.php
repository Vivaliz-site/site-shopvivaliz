<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

function ml_status_json(string $url, int $timeout = 3): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if (!is_string($body) || $body === '') {
        return ['ok' => false, 'http_code' => $code, 'error' => $error !== '' ? $error : 'empty_response'];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'http_code' => $code, 'error' => 'invalid_json'];
    }

    return ['ok' => $code >= 200 && $code < 300, 'http_code' => $code, 'data' => $decoded];
}

function ml_status_file(string $path): array
{
    if (!is_file($path)) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    $body = file_get_contents($path);
    $decoded = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'invalid_json'];
    }

    return ['ok' => true, 'data' => $decoded];
}

$health = ml_status_json('http://127.0.0.1:8091/health');
$training = ml_status_file(dirname(__DIR__, 2) . '/storage/ml/training-status.json');
$scores = ml_status_file(dirname(__DIR__, 2) . '/storage/ml/product-scores.json');

$response = [
    'ok' => ($health['ok'] ?? false) || ($training['ok'] ?? false),
    'checked_at' => gmdate('c'),
    'api' => $health,
    'training' => $training,
    'scores' => [
        'ok' => $scores['ok'] ?? false,
        'generated_at' => $scores['data']['generated_at'] ?? null,
        'model_version' => $scores['data']['model_version'] ?? null,
        'products_scored' => isset($scores['data']['scores']) && is_array($scores['data']['scores']) ? count($scores['data']['scores']) : 0,
    ],
];

if (!($response['ok'])) {
    http_response_code(503);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
