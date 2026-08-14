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
    // A borda HTTP (api/webhook-mercadopago.php) valida a assinatura x-signature
    // antes de criar o job. Exigir este marcador impede que um job criado sem
    // passar pela verificacao altere o status de pagamento de um pedido --
    // mesma defesa ja aplicada ao InfinitePay.
    if (($payload['auth_validated'] ?? false) !== true) {
        return ['success' => false, 'error' => 'auth_not_validated'];
    }

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

function sv_webhook_infinitepay_order_number(array $body): string
{
    $candidates = [
        $body['order_nsu'] ?? null,
        $body['order']['order_nsu'] ?? null,
        $body['data']['order_nsu'] ?? null,
        $body['metadata']['order_nsu'] ?? null,
        $body['reference'] ?? null,
        $body['external_reference'] ?? null,
        $body['order']['external_reference'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        $value = trim((string)$candidate);
        if (svmp_order_number_is_valid($value)) {
            return $value;
        }
    }
    return '';
}

function sv_webhook_infinitepay_provider_status(array $body): string
{
    $candidates = [
        $body['status'] ?? null,
        $body['payment_status'] ?? null,
        $body['payment']['status'] ?? null,
        $body['order']['status'] ?? null,
        $body['data']['status'] ?? null,
        $body['event'] ?? null,
        $body['type'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        $value = strtolower(trim((string)$candidate));
        if ($value !== '') return $value;
    }
    return 'pending';
}

function sv_webhook_infinitepay_local_status(string $providerStatus): string
{
    return match (strtolower(trim($providerStatus))) {
        'paid', 'approved', 'succeeded', 'success', 'completed', 'confirmed' => 'payment_approved',
        'pending', 'waiting_payment', 'waiting', 'created', 'processing', 'in_process' => 'payment_pending',
        'cancelled', 'canceled', 'expired', 'voided' => 'payment_cancelled',
        'refunded' => 'payment_refunded',
        'charged_back', 'chargeback' => 'payment_chargeback',
        'rejected', 'failed', 'denied', 'error' => 'payment_failed',
        default => 'payment_pending',
    };
}

function sv_webhook_infinitepay_payment_id(array $body): string
{
    foreach ([
        $body['payment_id'] ?? null,
        $body['payment']['id'] ?? null,
        $body['data']['payment_id'] ?? null,
        $body['transaction_id'] ?? null,
        $body['transaction']['id'] ?? null,
        $body['id'] ?? null,
    ] as $candidate) {
        $value = trim((string)$candidate);
        if ($value !== '') return substr($value, 0, 190);
    }
    return '';
}

function sv_webhook_job_dispatch_infinitepay(array $payload): array
{
    // A borda HTTP valida o segredo antes de criar o job. Exigir este marcador
    // impede que um job interno criado por engano sem autenticação altere um
    // pedido para pago.
    if (($payload['auth_validated'] ?? false) !== true) {
        return ['success' => false, 'error' => 'auth_not_validated'];
    }

    $body = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
    if ($body === [] && trim((string)($payload['raw'] ?? '')) !== '') {
        $decoded = json_decode((string)$payload['raw'], true);
        $body = is_array($decoded) ? $decoded : [];
    }
    if ($body === []) {
        return ['success' => false, 'error' => 'missing_provider_payload'];
    }

    $orderNumber = sv_webhook_infinitepay_order_number($body);
    if ($orderNumber === '') {
        return ['success' => true, 'message' => 'not_managed'];
    }

    $path = svmp_find_order_path($orderNumber);
    if ($path === '') {
        return ['success' => true, 'message' => 'order_not_found'];
    }

    $providerStatus = sv_webhook_infinitepay_provider_status($body);
    $localStatus = sv_webhook_infinitepay_local_status($providerStatus);
    $paymentId = sv_webhook_infinitepay_payment_id($body);

    $handle = fopen($path, 'r+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        return ['success' => false, 'error' => 'order_lock_unavailable'];
    }

    try {
        rewind($handle);
        $order = json_decode((string)stream_get_contents($handle), true);
        if (!is_array($order) || (string)($order['order_number'] ?? '') !== $orderNumber) {
            return ['success' => true, 'message' => 'order_invalid'];
        }

        $currentStatus = (string)($order['status'] ?? '');
        $terminalExceptions = ['payment_refunded', 'payment_chargeback'];
        // Never downgrade a confirmed payment because an older pending/failed
        // webhook arrives late. Refund and chargeback are the intentional
        // exceptions and must still supersede an approval.
        if ($currentStatus !== 'payment_approved' || in_array($localStatus, $terminalExceptions, true)) {
            $order['status'] = $localStatus;
        }

        $order['infinitepay'] = is_array($order['infinitepay'] ?? null) ? $order['infinitepay'] : [];
        if ($paymentId !== '') $order['infinitepay']['payment_id'] = $paymentId;
        $order['infinitepay']['status'] = $providerStatus;
        $order['infinitepay']['last_webhook_at'] = date(DATE_ATOM);
        $order['infinitepay']['last_webhook_request_id'] = substr(trim((string)($payload['request_id'] ?? '')), 0, 190);

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
                error_log('[InfinitePay] admin notify failed: order=' . $orderNumber . ' ' . $e->getMessage());
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

    return ['success' => true, 'message' => 'processed', 'status' => $localStatus];
}
