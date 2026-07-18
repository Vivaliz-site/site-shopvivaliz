<?php

declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__) . '/includes/mercadopago-gateway.php';
require_once dirname(__DIR__) . '/includes/tiny-order-push.php';

function svmp_webhook_response(int $status, string $result): never
{
    http_response_code($status);
    echo json_encode(['ok' => $status >= 200 && $status < 300, 'result' => $result]);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svmp_webhook_response(405, 'method_not_allowed');
}

$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 50000) {
    svmp_webhook_response(413, 'payload_too_large');
}
$body = json_decode($raw, true);
$body = is_array($body) ? $body : [];

$dataId = trim((string)($_GET['data.id'] ?? $_GET['data_id'] ?? $body['data']['id'] ?? ''));
$topic = strtolower(trim((string)($_GET['type'] ?? $body['type'] ?? '')));
$signature = trim((string)($_SERVER['HTTP_X_SIGNATURE'] ?? ''));
$requestId = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
$webhookSecret = svmp_env('MERCADOPAGO_WEBHOOK_SECRET');
$accessToken = svmp_env('MERCADOPAGO_ACCESS_TOKEN');

if ($webhookSecret === '' || $accessToken === '') {
    error_log('[MercadoPago] webhook unavailable: missing runtime configuration');
    svmp_webhook_response(503, 'gateway_unconfigured');
}
if (!svmp_validate_webhook_signature($signature, $requestId, $dataId, $webhookSecret)) {
    error_log('[MercadoPago] webhook rejected: invalid signature request=' . substr($requestId, 0, 80));
    svmp_webhook_response(401, 'invalid_signature');
}

try {
    $isOrder = $topic === 'order' || str_starts_with(strtoupper($dataId), 'ORD');
    $resource = svmp_api_request('GET', $isOrder ? '/v1/orders/' . rawurlencode($dataId) : '/v1/payments/' . rawurlencode($dataId), $accessToken);
    $externalReference = trim((string)($resource['external_reference'] ?? ''));
    if (!svmp_order_number_is_valid($externalReference)) {
        error_log('[MercadoPago] webhook ignored: external reference not managed resource=' . substr($dataId, 0, 80));
        svmp_webhook_response(200, 'not_managed');
    }

    $path = svmp_find_order_path($externalReference);
    if ($path === '') {
        error_log('[MercadoPago] webhook order not found: order=' . $externalReference);
        svmp_webhook_response(200, 'order_not_found');
    }

    $payment = $isOrder && is_array($resource['transactions']['payments'][0] ?? null)
        ? $resource['transactions']['payments'][0]
        : $resource;
    $providerStatus = (string)($payment['status'] ?? $resource['status'] ?? 'pending');
    $statusDetail = (string)($payment['status_detail'] ?? $resource['status_detail'] ?? '');
    $localStatus = svmp_local_status($providerStatus);

    $handle = fopen($path, 'r+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        svmp_webhook_response(503, 'order_lock_unavailable');
    }

    try {
        rewind($handle);
        $order = json_decode((string)stream_get_contents($handle), true);
        if (!is_array($order) || ($order['order_number'] ?? '') !== $externalReference) {
            svmp_webhook_response(200, 'order_invalid');
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
        $order['mercadopago']['status_detail'] = $statusDetail;
        $order['mercadopago']['last_webhook_at'] = date(DATE_ATOM);
        $order['mercadopago']['last_webhook_topic'] = $isOrder ? 'order' : 'payment';

        // O cliente nunca recebia o QR/codigo Pix por email -- so existia
        // envio apos pagamento aprovado. O Pix e criado inteiramente na
        // pagina hospedada do Checkout Pro (fora do nosso backend), entao o
        // unico jeito de capturar o QR e aqui no primeiro webhook em status
        // "pending" com point_of_interaction (metodo pix). Envia uma unica
        // vez por pedido.
        if ($localStatus !== 'payment_approved' && empty($order['pix_qr_email_sent'])) {
            try {
                $pixData = is_array($payment['point_of_interaction']['transaction_data'] ?? null)
                    ? $payment['point_of_interaction']['transaction_data']
                    : null;
                if ($pixData === null && $isOrder && !empty($payment['id'])) {
                    $paymentDetail = svmp_api_request('GET', '/v1/payments/' . rawurlencode((string)$payment['id']), $accessToken);
                    $pixData = is_array($paymentDetail['point_of_interaction']['transaction_data'] ?? null)
                        ? $paymentDetail['point_of_interaction']['transaction_data']
                        : null;
                }
                $qrCode = trim((string)($pixData['qr_code'] ?? ''));
                $qrCodeBase64 = trim((string)($pixData['qr_code_base64'] ?? ''));
                $customerEmailPix = (string)($order['customer']['email'] ?? '');
                if (($qrCode !== '' || $qrCodeBase64 !== '') && $customerEmailPix !== '') {
                    require_once dirname(__DIR__) . '/scripts/mailer.php';
                    $sent = svmp_send_pix_qr_email(
                        $customerEmailPix,
                        (string)($order['customer']['name'] ?? 'Cliente'),
                        $externalReference,
                        round((float)($order['total'] ?? 0), 2),
                        $qrCode,
                        $qrCodeBase64
                    );
                    $order['pix_qr_email_sent'] = $sent;
                }
            } catch (Throwable $e) {
                error_log('[MercadoPago] Pix QR email error: order=' . $externalReference . ' ' . $e->getMessage());
            }
        }

        // Pedidos pagos via Mercado Pago nao eram enviados ao Tiny ERP (apenas o
        // fluxo manual/offline em api/orders/create-v2.php fazia esse push).
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

        $encoded = json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        rewind($handle);
        ftruncate($handle, 0);
        if (fwrite($handle, $encoded) === false || !fflush($handle)) {
            svmp_webhook_response(500, 'order_write_failed');
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    error_log('[MercadoPago] webhook processed: order=' . $externalReference . ' resource=' . $dataId . ' status=' . $providerStatus);

    // Espelha o status na tabela MySQL `orders` (fonte usada por meus-pedidos.php).
    try {
        require_once dirname(__DIR__) . '/includes/pdo-database.php';
        require_once dirname(__DIR__) . '/includes/account-schema.php';
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

    // Enviar email de confirmação em background (se pagamento foi aprovado)
    if ($localStatus === 'payment_approved') {
        $postProcCmd = 'php ' . escapeshellarg(__DIR__ . '/webhook-post-processor.php') . ' ' .
                       escapeshellarg($externalReference) . ' ' .
                       escapeshellarg($path);

        // Executar em background (non-blocking)
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen('start /B ' . $postProcCmd, 'r'));
        } else {
            exec($postProcCmd . ' > /dev/null 2>&1 &');
        }

        error_log('[MercadoPago] email processor queued: order=' . $externalReference);

        // A etiqueta de transporte NAO e mais gerada aqui, na aprovacao do
        // pagamento. Comprar a etiqueta antes de a nota fiscal ser emitida
        // no ERP inverte a ordem real do processo fiscal/logistico -- o
        // gatilho correto e o webhook "notas fiscais autorizadas" da Tiny,
        // recebido em api/webhooks/tiny-nota-fiscal.php, que dispara o mesmo
        // api/melhorenvio/generate-label-background.php assim que a NF do
        // pedido e de fato emitida no ERP. Requer o app "Webhooks" configurado
        // na conta Tiny (UI, nao ha API pra isso) -- ver docs/TINY-ERP-API-V3.md.
    }

    svmp_webhook_response(200, 'processed');
} catch (SvMercadoPagoApiException $e) {
    error_log('[MercadoPago] webhook API failure: resource=' . substr($dataId, 0, 80) . ' code=' . $e->publicCode);
    svmp_webhook_response($e->httpStatus === 422 ? 200 : 502, $e->publicCode);
} catch (Throwable $e) {
    error_log('[MercadoPago] webhook internal failure: resource=' . substr($dataId, 0, 80) . ' type=' . get_class($e));
    svmp_webhook_response(500, 'internal_error');
}
