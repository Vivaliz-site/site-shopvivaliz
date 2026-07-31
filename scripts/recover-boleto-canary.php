<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/includes/mercadopago-boleto-orders-v2.php';
require_once $root . '/api/emails/send-order-notification.php';

function recovery_output(array $payload, int $exitCode): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($exitCode);
}

$orderNumber = trim((string)(getenv('SHOPVIVALIZ_RECOVERY_ORDER') ?: ''));
if (!svmp_order_number_is_valid($orderNumber)) {
    recovery_output(['ok' => false, 'error' => 'invalid_recovery_order'], 2);
}

$path = svmp_find_order_path($orderNumber);
if ($path === '') {
    recovery_output(['ok' => false, 'error' => 'recovery_order_not_found', 'order_number' => $orderNumber], 1);
}

$handle = fopen($path, 'r+');
if ($handle === false || !flock($handle, LOCK_EX)) {
    if (is_resource($handle)) fclose($handle);
    recovery_output(['ok' => false, 'error' => 'recovery_order_lock_unavailable', 'order_number' => $orderNumber], 1);
}

try {
    rewind($handle);
    $order = json_decode((string)stream_get_contents($handle), true);
    if (!is_array($order)) {
        throw new RuntimeException('invalid_order_file');
    }

    $notes = (string)($order['notes'] ?? '');
    $approved = ($order['status'] ?? '') === 'payment_approved'
        || ($order['mercadopago']['status'] ?? '') === 'approved';
    if (!str_contains($notes, '[CANARY ') || !str_contains($notes, 'NAO EXPEDIR') || $approved) {
        throw new RuntimeException('unsafe_recovery_target');
    }
    if (($order['payment_method'] ?? '') !== 'boleto') {
        throw new RuntimeException('recovery_payment_method_mismatch');
    }

    $order['test_order'] = true;
    $order['do_not_fulfill'] = true;
    $order['recovery_started_at'] = date(DATE_ATOM);
    $order['recovery_schema'] = 'boleto_orders_v3_minimal';

    $persist = static function () use ($handle, &$order): void {
        $encoded = json_encode(
            $order,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        rewind($handle);
        ftruncate($handle, 0);
        if (fwrite($handle, $encoded) === false || !fflush($handle)) {
            throw new RuntimeException('recovery_persistence_failed');
        }
    };
    $persist();

    $existing = is_array($order['mercadopago']['boleto'] ?? null) ? $order['mercadopago']['boleto'] : [];
    $reused = trim((string)($existing['ticket_url'] ?? '')) !== '';
    if ($reused) {
        $boleto = [
            'order_id' => (string)($order['mercadopago']['order_id'] ?? ''),
            'payment_id' => (string)($order['mercadopago']['payment_id'] ?? ''),
            'status' => (string)($existing['status'] ?? 'action_required'),
            'status_detail' => (string)($order['mercadopago']['status_detail'] ?? 'waiting_payment'),
            'ticket_url' => (string)$existing['ticket_url'],
            'digitable_line' => (string)($existing['digitable_line'] ?? ''),
            'barcode_content' => (string)($existing['barcode_content'] ?? ''),
        ];
    } else {
        $accessToken = svmp_env('MERCADOPAGO_ACCESS_TOKEN');
        if ($accessToken === '') throw new RuntimeException('gateway_unconfigured');
        $boleto = svmp_create_boleto_orders_v2($order, $accessToken);

        $order['status'] = 'payment_pending';
        $order['mercadopago'] = is_array($order['mercadopago'] ?? null) ? $order['mercadopago'] : [];
        $order['mercadopago']['provider'] = 'orders_api';
        $order['mercadopago']['schema'] = 'boleto_orders_v3_minimal';
        $order['mercadopago']['order_id'] = $boleto['order_id'];
        $order['mercadopago']['payment_id'] = $boleto['payment_id'];
        $order['mercadopago']['status'] = $boleto['status'];
        $order['mercadopago']['status_detail'] = $boleto['status_detail'];
        $order['mercadopago']['boleto'] = [
            'ticket_url' => $boleto['ticket_url'],
            'digitable_line' => $boleto['digitable_line'],
            'barcode_content' => $boleto['barcode_content'],
            'status' => $boleto['status'],
        ];
        $order['mercadopago']['created_at'] = date(DATE_ATOM);
        $persist();
    }

    $emailSent = (bool)($order['mercadopago']['boleto']['email_sent'] ?? false);
    if (!$emailSent) {
        $emailSent = svem_send_order_email($order, 'boleto_generated');
        $order['mercadopago']['boleto']['email_sent'] = $emailSent;
        $order['mercadopago']['boleto']['email_sent_at'] = date(DATE_ATOM);
    }

    $order['recovery_completed_at'] = date(DATE_ATOM);
    $order['recovery_ok'] = $emailSent;
    $persist();

    $tinyOrderId = trim((string)($order['tiny_order_id'] ?? ''));
    $tinyPush = trim((string)($order['tiny_push'] ?? ''));
    $result = [
        'ok' => $emailSent,
        'order_number' => $orderNumber,
        'reused_provider_order' => $reused,
        'payment_method' => (string)($order['payment_method'] ?? ''),
        'payment_status' => (string)($order['status'] ?? ''),
        'boleto_created' => trim((string)($boleto['ticket_url'] ?? '')) !== '',
        'digitable_line_present' => trim((string)($boleto['digitable_line'] ?? '')) !== '',
        'boleto_email_sent' => $emailSent,
        'tiny_not_imported_while_unpaid' => $tinyOrderId === '' && $tinyPush === '',
        'do_not_fulfill' => (bool)($order['do_not_fulfill'] ?? false),
        'schema' => 'boleto_orders_v3_minimal',
    ];

    foreach (['boleto_created', 'digitable_line_present', 'boleto_email_sent', 'tiny_not_imported_while_unpaid', 'do_not_fulfill'] as $assertion) {
        if ($result[$assertion] !== true) {
            $result['ok'] = false;
            $result['failed_assertion'] = $assertion;
            recovery_output($result, 1);
        }
    }

    error_log('[BoletoRecovery] order=' . $orderNumber . ' ok=yes schema=v3-minimal tiny_imported=no');
    recovery_output($result, 0);
} catch (Throwable $exception) {
    error_log('[BoletoRecovery] order=' . $orderNumber . ' ok=no type=' . get_class($exception));
    recovery_output([
        'ok' => false,
        'order_number' => $orderNumber,
        'error' => $exception instanceof SvMercadoPagoApiException
            ? $exception->publicCode
            : $exception->getMessage(),
    ], 1);
} finally {
    flock($handle, LOCK_UN);
    fclose($handle);
}
