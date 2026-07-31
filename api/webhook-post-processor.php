<?php
declare(strict_types=1);
/**
 * Webhook Post Processor
 * Executa após o webhook de pagamento aprovado, fora da resposta HTTP.
 * Envia confirmação ao cliente e registra a compra no GA4 uma única vez.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}

require_once __DIR__ . '/../includes/mercadopago-gateway.php';
require_once __DIR__ . '/../includes/OrderNotificationService.class.php';
require_once __DIR__ . '/../includes/ga4-order-conversion.php';

$orderNumberArgument = trim((string)($argv[1] ?? ''));
$orderPath = trim((string)($argv[2] ?? ''));
if ($orderNumberArgument === '' || $orderPath === '' || !is_file($orderPath)) {
    fwrite(STDERR, "Usage: php webhook-post-processor.php ORDER_NUMBER ORDER_PATH\n");
    exit(1);
}

$handle = fopen($orderPath, 'r+');
if ($handle === false || !flock($handle, LOCK_EX)) {
    if (is_resource($handle)) {
        fclose($handle);
    }
    fwrite(STDERR, "Order lock unavailable.\n");
    exit(1);
}

try {
    rewind($handle);
    $orderData = json_decode((string)stream_get_contents($handle), true);
    if (!is_array($orderData)) {
        throw new RuntimeException('invalid_order_file');
    }

    $orderNumber = trim((string)($orderData['order_number'] ?? $orderNumberArgument));
    $status = trim((string)($orderData['status'] ?? 'pending'));
    $mpStatus = trim((string)($orderData['mercadopago']['status'] ?? 'pending'));
    $approved = $status === 'payment_approved' || $mpStatus === 'approved';

    if (!$approved) {
        echo json_encode([
            'ok' => true,
            'order_number' => $orderNumber,
            'payment_approved' => false,
            'customer_email_sent' => false,
            'ga4_purchase_sent' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $customer = is_array($orderData['customer'] ?? null) ? $orderData['customer'] : [];
    $customerEmail = trim((string)($customer['email'] ?? $orderData['customer_email'] ?? ''));
    if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('invalid_customer_email');
    }

    $emailSent = (bool)($orderData['payment_confirmation_email_sent'] ?? false);
    if (!$emailSent) {
        try {
            $service = OrderNotificationService::getInstance();
            $emailSent = $service->notifyOrderEvent(
                $orderNumber,
                'pagamento_aprovado',
                $orderData,
                trim((string)($orderData['mercadopago']['payment_id'] ?? '')) ?: null
            );
        } catch (Throwable $exception) {
            error_log('[PaymentPostProcessor] notification service failed: order=' . $orderNumber . ' type=' . get_class($exception));
        }

        if (!$emailSent) {
            require_once __DIR__ . '/../api/emails/send-order-notification.php';
            $emailSent = svem_send_order_email($orderData, 'payment_received');
        }

        $orderData['payment_confirmation_email_sent'] = $emailSent;
        $orderData['payment_confirmation_email_sent_at'] = $emailSent ? date(DATE_ATOM) : null;
    }

    $ga4Sent = (bool)($orderData['analytics_purchase_sent'] ?? false);
    if (!$ga4Sent) {
        $ga4Sent = svga4_send_approved_purchase($orderData);
        $orderData['analytics_purchase_sent'] = $ga4Sent;
        $orderData['analytics_purchase_sent_at'] = $ga4Sent ? date(DATE_ATOM) : null;
    }

    $encoded = json_encode(
        $orderData,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    rewind($handle);
    ftruncate($handle, 0);
    if (fwrite($handle, $encoded) === false || !fflush($handle)) {
        throw new RuntimeException('order_write_failed');
    }

    error_log(
        '[PaymentPostProcessor] order=' . $orderNumber
        . ' email=' . ($emailSent ? 'sent' : 'failed')
        . ' ga4=' . ($ga4Sent ? 'sent' : 'not_sent')
    );

    echo json_encode([
        'ok' => $emailSent,
        'order_number' => $orderNumber,
        'payment_approved' => true,
        'customer_email_sent' => $emailSent,
        'ga4_purchase_sent' => $ga4Sent,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($emailSent ? 0 : 1);
} catch (Throwable $exception) {
    error_log('[PaymentPostProcessor] failure: order=' . $orderNumberArgument . ' type=' . get_class($exception));
    fwrite(STDERR, json_encode([
        'ok' => false,
        'order_number' => $orderNumberArgument,
        'error' => 'post_processing_failed',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
} finally {
    flock($handle, LOCK_UN);
    fclose($handle);
}
