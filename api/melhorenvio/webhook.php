<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/includes/melhorenvio-oauth.php';
require_once dirname(__DIR__, 2) . '/includes/mercadopago-gateway.php';

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

/**
 * order.* -> status legivel gravado no pedido local. Nao mapeia pra um
 * enum de order_status ja existente porque o site nao tinha um antes --
 * so grava a string, minha-conta/pedidos.php ja exibe tracking_number/
 * tracking_url quando presentes.
 */
function me_status_label(string $event): string
{
    return match ($event) {
        'order.created' => 'Etiqueta criada',
        'order.pending' => 'Etiqueta pendente',
        'order.released' => 'Etiqueta paga',
        'order.generated' => 'Etiqueta gerada',
        'order.received' => 'Recebido em ponto de distribuição',
        'order.posted' => 'Postado',
        'order.delivered' => 'Entregue',
        'order.cancelled' => 'Cancelado',
        'order.undelivered' => 'Não entregue',
        'order.paused' => 'Entrega pausada',
        'order.suspended' => 'Suspenso',
        default => '',
    };
}

function me_find_order_by_shipment(string $shipmentId): array
{
    if ($shipmentId === '') {
        return [];
    }
    foreach (svmp_order_directories() as $directory) {
        if (!is_dir($directory)) {
            continue;
        }
        foreach (glob($directory . DIRECTORY_SEPARATOR . 'SV*.json') ?: [] as $file) {
            $order = json_decode((string)file_get_contents($file), true);
            if (!is_array($order)) {
                continue;
            }
            if ((string)($order['melhorenvio_shipment_id'] ?? '') === $shipmentId) {
                return ['path' => $file, 'order' => $order];
            }
        }
    }
    return [];
}

$shipmentId = (string)($data['id'] ?? '');
$tracking = trim((string)($data['tracking'] ?? ''));
$trackingUrl = trim((string)($data['tracking_url'] ?? ''));
$statusLabel = me_status_label($event);
$applied = false;

if ($shipmentId !== '' && ($tracking !== '' || $trackingUrl !== '' || $statusLabel !== '')) {
    $found = me_find_order_by_shipment($shipmentId);
    if ($found !== []) {
        $order = $found['order'];
        if ($tracking !== '') {
            $order['tracking_number'] = $tracking;
        }
        if ($trackingUrl !== '') {
            $order['tracking_url'] = $trackingUrl;
        }
        if ($statusLabel !== '') {
            $order['shipping_status'] = $statusLabel;
        }
        $order['melhorenvio_last_event'] = $event;
        $order['melhorenvio_last_event_at'] = date('c');
        if (file_put_contents($found['path'], json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
            $applied = true;
        }

        try {
            require_once dirname(__DIR__, 2) . '/includes/pdo-database.php';
            require_once dirname(__DIR__, 2) . '/includes/account-schema.php';
            sv_account_ensure_schema();
            $pdo = sv_pdo();
            $stmt = $pdo->prepare(
                'UPDATE orders SET
                     tracking_number = COALESCE(NULLIF(:tracking, ""), tracking_number),
                     tracking_url = COALESCE(NULLIF(:tracking_url, ""), tracking_url),
                     updated_at = NOW()
                 WHERE order_number = :order_number'
            );
            $stmt->execute([
                ':tracking' => $tracking,
                ':tracking_url' => $trackingUrl,
                ':order_number' => (string)($order['order_number'] ?? ''),
            ]);
        } catch (Throwable $e) {
            error_log('[MelhorEnvio] MySQL orders tracking update failed: ' . $e->getMessage());
        }
    } else {
        error_log('[MelhorEnvio] shipment_id=' . $shipmentId . ' nao corresponde a nenhum pedido local (event=' . $event . ')');
    }
}

me_webhook_response(200, [
    'ok' => true,
    'provider' => 'melhorenvio',
    'event_type' => 'shipping_event',
    'event' => $event,
    'status' => (string)($data['status'] ?? ''),
    'tracking' => $tracking,
    'protocol' => (string)($data['protocol'] ?? ''),
    'event_id' => 'melhorenvio:' . md5($raw !== '' ? $raw : json_encode($_REQUEST)),
    'received_at' => date('c'),
    'order_updated' => $applied,
    'message' => 'Webhook recebido e autenticado.',
]);

