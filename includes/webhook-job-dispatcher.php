<?php
declare(strict_types=1);

require_once __DIR__ . '/mercadopago-gateway.php';
require_once __DIR__ . '/tiny-order-push.php';
require_once __DIR__ . '/../api/emails/send-order-notification.php';
require_once __DIR__ . '/ml-event-tracker.php';
require_once __DIR__ . '/payment-notification-idempotency.php';
require_once __DIR__ . '/webhook-queue.php';
// Rodada 9 (2026-08-19): requires abaixo adicionados para portar os efeitos
// que o refactor de 09/08 deixou de fora do dispatcher (ver R9-1/R9-2 no
// relatorio da Rodada 9 e docs/AGENTS.md). scripts/mailer.php ja e
// require-once em outros pontos, entao repetir aqui e seguro (idempotente).
require_once __DIR__ . '/pdo-database.php';
require_once __DIR__ . '/account-schema.php';
require_once __DIR__ . '/../scripts/mailer.php';

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
        $order['mercadopago']['order_id'] = $isOrder ? (string)($resource['id'] ?? $dataId) : (string)($order['mercadopago']['order_id'] ?? '');
        $order['mercadopago']['payment_id'] = (string)($payment['id'] ?? $dataId);
        $order['mercadopago']['status'] = $providerStatus;
        $order['mercadopago']['status_detail'] = (string)($payment['status_detail'] ?? $resource['status_detail'] ?? '');
        $order['mercadopago']['payment_method_id'] = trim((string)($payment['payment_method_id'] ?? $payment['payment_method']['id'] ?? ''));
        $order['mercadopago']['payment_type_id'] = trim((string)($payment['payment_type_id'] ?? $payment['payment_type']['id'] ?? ''));
        $order['mercadopago']['card_brand'] = trim((string)($payment['card']['brand'] ?? $payment['payment_method_id'] ?? ''));
        $order['mercadopago']['installments'] = (int)($payment['installments'] ?? 1);
        $order['mercadopago']['authorization_code'] = trim((string)($payment['transaction_details']['authorization_code'] ?? $payment['authorization_code'] ?? ''));
        $order['mercadopago']['transaction_amount'] = round((float)($payment['transaction_amount'] ?? $order['total'] ?? 0), 2);
        $order['mercadopago']['last_webhook_at'] = date(DATE_ATOM);
        $order['mercadopago']['last_webhook_topic'] = $isOrder ? 'order' : 'payment';

        $notifyAdminPayment = svpn_prepare_admin_payment_notification($order, $localStatus, $currentStatus);

        // Rodada 9 (2026-08-19): daqui ate o push pro Tiny, os efeitos abaixo
        // (captura de boleto/email, QR Pix por email, push Tiny/Olist) sao a
        // parte do fluxo sincrono antigo que o refactor de 09/08 deixou de
        // fora do dispatcher. Portados de api/webhook-mercadopago.php
        // (bloco morto, linhas 155-421). Ver R9-2 no relatorio da Rodada 9.
        $paymentMethodId = strtolower((string)$order['mercadopago']['payment_method_id']);
        $paymentTypeId = strtolower((string)$order['mercadopago']['payment_type_id']);
        if ($localStatus !== 'payment_approved' && $paymentTypeId === 'ticket' && empty($order['mercadopago']['boleto']['email_sent'])) {
            try {
                $paymentDetail = [];
                if (!$isOrder && !empty($payment['id'])) {
                    $paymentDetail = svmp_api_request('GET', '/v1/payments/' . rawurlencode((string)$payment['id']), $accessToken);
                }
                $boletoData = svmp_webhook_extract_boleto($payment, is_array($paymentDetail) ? $paymentDetail : []);
                if ($boletoData['ticket_url'] !== '' || $boletoData['digitable_line'] !== '' || $boletoData['barcode_content'] !== '') {
                    $order['mercadopago']['boleto'] = array_merge(
                        is_array($order['mercadopago']['boleto'] ?? null) ? $order['mercadopago']['boleto'] : [],
                        $boletoData,
                        [
                            'status' => $providerStatus,
                            'payment_method_id' => $paymentMethodId,
                            'source' => 'webhook',
                        ]
                    );
                    $sentBoleto = svmp_webhook_send_boleto_email($order);
                    $order['mercadopago']['boleto']['email_sent'] = $sentBoleto;
                    $order['mercadopago']['boleto']['email_sent_at'] = date(DATE_ATOM);
                }
            } catch (Throwable $e) {
                error_log('[MercadoPago] boleto webhook capture error: order=' . $externalReference . ' ' . $e->getMessage());
            }
        }

        // O QR/codigo Pix so existe na pagina hospedada do Checkout Pro; o
        // unico jeito de capturar e no primeiro webhook em status "pending"
        // com point_of_interaction (metodo pix). Envia uma unica vez por pedido.
        if ($localStatus !== 'payment_approved' && empty($order['pix_qr_email_sent'])) {
            try {
                $pixData = is_array($payment['point_of_interaction']['transaction_data'] ?? null)
                    ? $payment['point_of_interaction']['transaction_data']
                    : null;
                if ($pixData === null && $isOrder && !empty($payment['id'])) {
                    $paymentDetailPix = svmp_api_request('GET', '/v1/payments/' . rawurlencode((string)$payment['id']), $accessToken);
                    $pixData = is_array($paymentDetailPix['point_of_interaction']['transaction_data'] ?? null)
                        ? $paymentDetailPix['point_of_interaction']['transaction_data']
                        : null;
                }
                $qrCode = trim((string)($pixData['qr_code'] ?? ''));
                $qrCodeBase64 = trim((string)($pixData['qr_code_base64'] ?? ''));
                $customerEmailPix = (string)($order['customer']['email'] ?? '');
                if (($qrCode !== '' || $qrCodeBase64 !== '') && $customerEmailPix !== '') {
                    $sentPix = svmp_send_pix_qr_email(
                        $customerEmailPix,
                        (string)($order['customer']['name'] ?? 'Cliente'),
                        $externalReference,
                        round((float)($order['total'] ?? 0), 2),
                        $qrCode,
                        $qrCodeBase64
                    );
                    $order['pix_qr_email_sent'] = $sentPix;
                }
            } catch (Throwable $e) {
                error_log('[MercadoPago] Pix QR email error: order=' . $externalReference . ' ' . $e->getMessage());
            }
        }

        // Push pro Tiny/Olist ERP quando o pagamento e aprovado.
        if ($localStatus === 'payment_approved' && empty($order['tiny_order_id'])) {
            if (svtop_tiny_credentials_configured()) {
                try {
                    $tinyOrderId = svtop_push_order_tiny($order);
                    if ($tinyOrderId) {
                        $order['tiny_order_id'] = $tinyOrderId;
                        $order['tiny_push'] = 'ok';
                    } else {
                        $order['tiny_push'] = 'token_unavailable';
                    }
                } catch (Throwable $e) {
                    $order['tiny_push'] = $e->getMessage();
                    error_log('[MercadoPago] Tiny push error: order=' . $externalReference . ' ' . $e->getMessage());
                }
            } else {
                $order['tiny_push'] = 'missing_credentials';
            }
        }

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

    // Rodada 9 (2026-08-19): espelho MySQL do status (fonte de meus-pedidos.php)
    // + disparo do pos-processador (e-mail de confirmacao + conversao GA4) +
    // tracking de purchase por item -- todos ausentes do dispatcher desde o
    // refactor de 09/08. Ver R9-2 no relatorio da Rodada 9.
    try {
        sv_account_ensure_schema();
        $orderStatusMap = [
            'payment_approved' => 'pagamento_aprovado',
            'payment_pending' => 'aguardando_pagamento',
            'payment_refunded' => 'devolvido',
            'payment_chargeback' => 'devolvido',
            'payment_cancelled' => 'cancelado',
            'payment_failed' => 'cancelado',
        ];
        $mappedStatus = $orderStatusMap[$localStatus] ?? 'aguardando_pagamento';
        $pdo = sv_pdo();
        $stmt = $pdo->prepare(
            'UPDATE orders SET order_status = :status, olist_order_id = COALESCE(:olist_order_id, olist_order_id), updated_at = NOW()
             WHERE order_number = :order_number'
        );
        $stmt->execute([
            ':status' => $mappedStatus,
            ':olist_order_id' => $order['tiny_order_id'] ?? null,
            ':order_number' => $externalReference,
        ]);
    } catch (Throwable $e) {
        error_log('[MercadoPago] MySQL orders mirror failed: order=' . $externalReference . ' ' . $e->getMessage());
    }

    if ($localStatus === 'payment_approved') {
        foreach (is_array($order['items'] ?? null) ? $order['items'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = (string)($item['olist_product_id'] ?? $item['sku'] ?? '');
            if ($productId === '') {
                continue;
            }
            svml_track_event('purchase', $productId, [
                'source' => 'mercadopago_webhook',
                'order_number' => $externalReference,
                'payment_id' => (string)($order['mercadopago']['payment_id'] ?? ''),
            ]);
        }
        sv_webhook_job_run_post_processor($externalReference, $path);
    }

    return ['success' => true, 'message' => 'processed'];
}

// Rodada 9 (2026-08-19): dispara api/webhook-post-processor.php de forma
// SINCRONA (blocking) -- ja estamos dentro de um worker de fila em segundo
// plano, entao nao ha necessidade de duplicar o padrao "background &" que o
// fluxo HTTP sincrono antigo usava pra nao travar a resposta ao cliente.
// Reutiliza o script existente (ja testado, idempotente via
// payment_confirmation_email_sent/analytics_purchase_sent) em vez de
// duplicar a logica de e-mail de confirmacao + conversao GA4 aqui.
function sv_webhook_job_run_post_processor(string $orderNumber, string $orderPath): void
{
    $cmd = 'php ' . escapeshellarg(dirname(__DIR__) . '/api/webhook-post-processor.php')
        . ' ' . escapeshellarg($orderNumber)
        . ' ' . escapeshellarg($orderPath)
        . ' 2>&1';
    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    if ($exitCode !== 0) {
        error_log('[webhook-job-dispatcher] post-processor falhou (exit=' . $exitCode . ') order=' . $orderNumber . ' output=' . implode(' | ', $output));
    }
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

        // Rodada 9 (2026-08-19): push pro Tiny/Olist -- o dispatcher do
        // InfinitePay nunca teve isso, nem antes do refactor de 09/08 (este
        // gateway sempre foi so-fila, sem caminho sincrono antigo). Mesmo
        // padrao aplicado ao Mercado Pago. Ver R9-2 no relatorio da Rodada 9.
        if ($localStatus === 'payment_approved' && empty($order['tiny_order_id'])) {
            if (svtop_tiny_credentials_configured()) {
                try {
                    $tinyOrderId = svtop_push_order_tiny($order);
                    if ($tinyOrderId) {
                        $order['tiny_order_id'] = $tinyOrderId;
                        $order['tiny_push'] = 'ok';
                    } else {
                        $order['tiny_push'] = 'token_unavailable';
                    }
                } catch (Throwable $e) {
                    $order['tiny_push'] = $e->getMessage();
                    error_log('[InfinitePay] Tiny push error: order=' . $orderNumber . ' ' . $e->getMessage());
                }
            } else {
                $order['tiny_push'] = 'missing_credentials';
            }
        }

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

    // Rodada 9 (2026-08-19): mesmos efeitos pos-escrita do Mercado Pago
    // (espelho MySQL, e-mail de confirmacao + GA4, tracking de purchase).
    // Ver R9-2 no relatorio da Rodada 9.
    try {
        sv_account_ensure_schema();
        $orderStatusMap = [
            'payment_approved' => 'pagamento_aprovado',
            'payment_pending' => 'aguardando_pagamento',
            'payment_refunded' => 'devolvido',
            'payment_chargeback' => 'devolvido',
            'payment_cancelled' => 'cancelado',
            'payment_failed' => 'cancelado',
        ];
        $mappedStatus = $orderStatusMap[$localStatus] ?? 'aguardando_pagamento';
        $pdo = sv_pdo();
        $stmt = $pdo->prepare(
            'UPDATE orders SET order_status = :status, olist_order_id = COALESCE(:olist_order_id, olist_order_id), updated_at = NOW()
             WHERE order_number = :order_number'
        );
        $stmt->execute([
            ':status' => $mappedStatus,
            ':olist_order_id' => $order['tiny_order_id'] ?? null,
            ':order_number' => $orderNumber,
        ]);
    } catch (Throwable $e) {
        error_log('[InfinitePay] MySQL orders mirror failed: order=' . $orderNumber . ' ' . $e->getMessage());
    }

    if ($localStatus === 'payment_approved') {
        foreach (is_array($order['items'] ?? null) ? $order['items'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = (string)($item['olist_product_id'] ?? $item['sku'] ?? '');
            if ($productId === '') {
                continue;
            }
            svml_track_event('purchase', $productId, [
                'source' => 'infinitepay_webhook',
                'order_number' => $orderNumber,
                'payment_id' => (string)($order['infinitepay']['payment_id'] ?? ''),
            ]);
        }
        sv_webhook_job_run_post_processor($orderNumber, $path);
    }

    return ['success' => true, 'message' => 'processed', 'status' => $localStatus];
}
