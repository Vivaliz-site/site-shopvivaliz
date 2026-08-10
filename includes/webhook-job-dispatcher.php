<?php
declare(strict_types=1);

require_once __DIR__ . '/mercadopago-gateway.php';
require_once __DIR__ . '/tiny-order-push.php';
require_once __DIR__ . '/../api/emails/send-order-notification.php';
require_once __DIR__ . '/ml-event-tracker.php';
require_once __DIR__ . '/payment-notification-idempotency.php';
require_once __DIR__ . '/webhook-queue.php';

function sv_webhook_job_dispatch(array $job): array
{
    $type = (string)($job['job_type'] ?? '');
    $payload = json_decode((string)($job['payload'] ?? '{}'), true);
    if (!is_array($payload)) {
        return ['success' => false, 'error' => 'invalid_payload'];
    }

    if ($type === 'webhook:mercadopago') {
        return sv_webhook_job_dispatch_mercadopago($payload);
    }

    if ($type === 'webhook:infinitepay') {
        return sv_webhook_job_dispatch_infinitepay($payload);
    }

    return ['success' => true, 'message' => 'ignored_job_type'];
}

function sv_webhook_job_dispatch_mercadopago(array $payload): array
{
    $dataId = trim((string)($payload['data_id'] ?? ''));
    $topic = strtolower(trim((string)($payload['topic'] ?? '')));
    if ($dataId === '') {
        return ['success' => false, 'error' => 'missing_data_id'];
    }
    if (str_contains($topic, 'merchant_order')) {
        return ['success' => true, 'message' => 'ignored_topic'];
    }

    $accessToken = svmp_env('MERCADOPAGO_ACCESS_TOKEN');
    if ($accessToken === '') {
        return ['success' => false, 'error' => 'missing_access_token'];
    }

    $raw = (string)($payload['raw'] ?? '');
    $body = json_decode($raw, true);
    $body = is_array($body) ? $body : [];
    $isOrder = $topic === 'order' || str_starts_with(strtoupper($dataId), 'ORD');

    $resource = svmp_api_request('GET', $isOrder ? '/v1/orders/' . rawurlencode($dataId) : '/v1/payments/' . rawurlencode($dataId), $accessToken);
    $externalReference = trim((string)($resource['external_reference'] ?? ''));
    if (!svmp_order_number_is_valid($externalReference)) {
        return ['success' => true, 'message' => 'not_managed'];
    }

    $path = svmp_find_order_path($externalReference);
    if ($path === '') {
        return ['success' => true, 'message' => 'order_not_found'];
    }

    $payment = $isOrder && is_array($resource['transactions']['payments'][0] ?? null)
        ? $resource['transactions']['payments'][0]
        : $resource;
    $providerStatus = (string)($payment['status'] ?? $resource['status'] ?? 'pending');
    $localStatus = svmp_local_status($providerStatus);

    $handle = fopen($path, 'r+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return ['success' => false, 'error' => 'order_lock_unavailable'];
    }

    try {
        rewind($handle);
        $order = json_decode((string)stream_get_contents($handle), true);
        if (!is_array($order) || ($order['order_number'] ?? '') !== $externalReference) {
            return ['success' => true, 'message' => 'order_invalid'];
        }

        $currentStatus = (string)($order['status'] ?? '');
        $terminalExceptions = ['payment_refunded', 'payment_chargeback'];
        if ($currentStatus !== 'payment_approved' || in_array($localStatus, $terminalExceptions, true)) {
            $order['status'] = $localStatus;
        }
        $order['mercadopago'] = is_array($order['mercadopago'] ?? null) ? $order['mercadopago'] : [];
        $order['mercadopago']['payment_id'] = (string)($payment['id'] ?? $dataId);
        $order['mercadopago']['status'] = $providerStatus;
        $order['mercadopago']['last_webhook_at'] = date(DATE_ATOM);
        $order['mercadopago']['last_webhook_topic'] = $isOrder ? 'order' : 'payment';

        $notifyAdminPayment = svpn_prepare_admin_payment_notification($order, $localStatus, $currentStatus);
        if ($notifyAdminPayment) {
            $claimed = json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            rewind($handle);
            ftruncate($handle, 0);
            if (fwrite($handle, $claimed) === false || !fflush($handle)) {
                return ['success' => false, 'error' => 'notification_claim_write_failed'];
            }
            $sent = false;
            try {
                $sent = svem_notify_admin_payment_received($order);
            } catch (Throwable $e) {
                error_log('[MercadoPago] admin notify failed: order=' . $externalReference . ' ' . $e->getMessage());
            }
            svpn_complete_admin_payment_notification($order, $sent);
        }

        $encoded = json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        rewind($handle);
        ftruncate($handle, 0);
        if (fwrite($handle, $encoded) === false || !fflush($handle)) {
            return ['success' => false, 'error' => 'order_write_failed'];
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    return ['success' => true, 'message' => 'processed'];
}

function sv_webhook_job_dispatch_infinitepay(array $payload): array
{
    // A autenticacao ja foi validada na borda HTTP antes do enfileiramento.
    // O job persistente nao deve carregar o token original.
    return ['success' => true, 'message' => 'processed'];
}
