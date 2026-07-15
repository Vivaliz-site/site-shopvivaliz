<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Callback OAuth: Melhor Envio redireciona para ca com ?code=... apos o
// usuario autorizar o app. Troca o codigo por access_token/refresh_token
// e salva em storage/private/melhorenvio-tokens.json (mesmo padrao usado
// para o Mercado Livre em api/ml/client.php).
if (isset($_GET['code']) && is_string($_GET['code']) && $_GET['code'] !== '') {
    require_once dirname(__DIR__, 2) . '/includes/melhorenvio-oauth.php';
    $code = trim((string)$_GET['code']);
    $result = me_exchange_code($code);
    if (!empty($result['access_token'])) {
        me_save_tokens($result);
        echo json_encode([
            'ok' => true,
            'provider' => 'melhorenvio',
            'event_type' => 'oauth_callback',
            'message' => 'Token do Melhor Envio obtido e salvo com sucesso.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    } else {
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'provider' => 'melhorenvio',
            'event_type' => 'oauth_callback',
            'error' => 'token_exchange_failed',
            'detail' => $result,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$eventId = 'melhorenvio:' . md5($raw !== '' ? $raw : json_encode($_REQUEST));

echo json_encode([
    'ok' => true,
    'provider' => 'melhorenvio',
    'event_type' => 'shipping_event',
    'event_id' => $eventId,
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'received_at' => date('c'),
    'message' => 'Webhook recebido pelo ShopVivaliz.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

