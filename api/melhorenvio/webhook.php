<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/includes/melhorenvio-oauth.php';

function me_webhook_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function me_first_non_empty_string(array $values): string
{
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function me_webhook_secret(): string
{
    return me_first_non_empty_string([
        getenv('MELHORENVIO_WEBHOOK_SECRET'),
        getenv('MELHORENVIO_CLIENT_SECRET'),
        getenv('MELHORENVIO_CLIENTE_SECRET'),
    ]);
}

function me_validate_signature(string $rawBody, string $signature, string $secret): bool
{
    if ($rawBody === '' || $signature === '' || $secret === '') {
        return false;
    }
    $signature = trim($signature);
    if (str_starts_with($signature, 'sha256=')) {
        $signature = substr($signature, 7);
    }
    $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
    return hash_equals($expected, $signature);
}

// Callback OAuth: Melhor Envio redireciona para ca com ?code=... apos o
// usuario autorizar o app. Troca o codigo por access_token/refresh_token
// e salva em storage/private/melhorenvio-tokens.json (mesmo padrao usado
// para o Mercado Livre em api/ml/client.php).
if (isset($_GET['code']) && is_string($_GET['code']) && $_GET['code'] !== '') {
    $code = trim((string)$_GET['code']);
    $result = me_exchange_code($code);
    if (!empty($result['access_token'])) {
        me_save_tokens($result);
        me_webhook_response(200, [
            'ok' => true,
            'provider' => 'melhorenvio',
            'event_type' => 'oauth_callback',
            'message' => 'Token do Melhor Envio obtido e salvo com sucesso.',
        ]);
    }

    me_webhook_response(502, [
        'ok' => false,
        'provider' => 'melhorenvio',
        'event_type' => 'oauth_callback',
        'error' => 'token_exchange_failed',
        'detail' => $result,
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    me_webhook_response(405, [
        'ok' => false,
        'provider' => 'melhorenvio',
        'error' => 'method_not_allowed',
    ]);
}

$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 100000) {
    me_webhook_response(413, [
        'ok' => false,
        'provider' => 'melhorenvio',
        'error' => 'payload_too_large',
    ]);
}

$body = json_decode($raw, true);
if (!is_array($body)) {
    me_webhook_response(400, [
        'ok' => false,
        'provider' => 'melhorenvio',
        'error' => 'invalid_json',
    ]);
}

$signature = trim((string)($_SERVER['HTTP_X_ME_SIGNATURE'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? ''));
if ($signature === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $headerName => $headerValue) {
        if (strcasecmp($headerName, 'X-ME-Signature') === 0 || strcasecmp($headerName, 'X-Signature') === 0) {
            $signature = trim((string)$headerValue);
            break;
        }
    }
}

$secret = me_webhook_secret();
if ($secret === '') {
    error_log('[MelhorEnvio] webhook unavailable: secret not configured');
    me_webhook_response(503, [
        'ok' => false,
        'provider' => 'melhorenvio',
        'error' => 'webhook_unconfigured',
    ]);
}

if (!me_validate_signature($raw, $signature, $secret)) {
    error_log('[MelhorEnvio] invalid signature event=' . substr((string)($body['event'] ?? ''), 0, 80));
    me_webhook_response(401, [
        'ok' => false,
        'provider' => 'melhorenvio',
        'error' => 'invalid_signature',
    ]);
}

$event = trim((string)($body['event'] ?? ''));
$data = is_array($body['data'] ?? null) ? $body['data'] : [];
$eventId = me_first_non_empty_string([
    $data['id'] ?? '',
    $data['protocol'] ?? '',
    $event,
]);

me_webhook_response(200, [
    'ok' => true,
    'provider' => 'melhorenvio',
    'event_type' => 'shipping_event',
    'event' => $event,
    'status' => (string)($data['status'] ?? ''),
    'tracking' => (string)($data['tracking'] ?? ''),
    'protocol' => (string)($data['protocol'] ?? ''),
    'event_id' => 'melhorenvio:' . md5($raw !== '' ? $raw : json_encode($_REQUEST)),
    'received_at' => date('c'),
    'message' => 'Webhook recebido e autenticado.',
]);

