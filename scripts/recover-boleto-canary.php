<?php
declare(strict_types=1);

/**
 * Read-only reconciliation for the existing boleto canary.
 *
 * This script never creates a provider order, never sends email, never changes
 * the order file and never removes prior evidence. Missing or ambiguous state
 * is a hard failure that requires human reconciliation with the provider.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/includes/mercadopago-boleto-orders-v2.php';

function reconciliation_output(array $payload, int $exitCode): never
{
    echo json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) . PHP_EOL;
    exit($exitCode);
}

$orderNumber = trim((string)(getenv('SHOPVIVALIZ_RECOVERY_ORDER') ?: ''));
if (!svmp_order_number_is_valid($orderNumber)) {
    reconciliation_output([
        'ok' => false,
        'error' => 'invalid_reconciliation_order',
        'reconciliation_only' => true,
        'side_effects_attempted' => false,
    ], 2);
}

$path = svmp_find_order_path($orderNumber);
if ($path === '') {
    reconciliation_output([
        'ok' => false,
        'error' => 'reconciliation_order_not_found',
        'order_number' => $orderNumber,
        'reconciliation_only' => true,
        'side_effects_attempted' => false,
    ], 1);
}

$handle = fopen($path, 'rb');
if ($handle === false || !flock($handle, LOCK_SH)) {
    if (is_resource($handle)) {
        fclose($handle);
    }
    reconciliation_output([
        'ok' => false,
        'error' => 'reconciliation_order_lock_unavailable',
        'order_number' => $orderNumber,
        'reconciliation_only' => true,
        'side_effects_attempted' => false,
    ], 1);
}

try {
    $beforeHash = hash_file('sha256', $path);
    if ($beforeHash === false) {
        throw new RuntimeException('order_hash_unavailable');
    }

    rewind($handle);
    $order = json_decode((string)stream_get_contents($handle), true);
    if (!is_array($order)) {
        throw new RuntimeException('invalid_order_file');
    }

    $notes = (string)($order['notes'] ?? '');
    $approved = ($order['status'] ?? '') === 'payment_approved'
        || ($order['mercadopago']['status'] ?? '') === 'approved';
    if (!str_contains($notes, '[CANARY ') || !str_contains($notes, 'NAO EXPEDIR') || $approved) {
        throw new RuntimeException('unsafe_reconciliation_target');
    }
    if (($order['payment_method'] ?? '') !== 'boleto') {
        throw new RuntimeException('reconciliation_payment_method_mismatch');
    }

    $provider = is_array($order['mercadopago'] ?? null) ? $order['mercadopago'] : [];
    $boleto = is_array($provider['boleto'] ?? null) ? $provider['boleto'] : [];

    $persistedProviderOrder = trim((string)($provider['order_id'] ?? '')) !== ''
        && trim((string)($boleto['ticket_url'] ?? '')) !== '';
    $digitableLinePresent = trim((string)($boleto['digitable_line'] ?? '')) !== '';
    $emailStatePersisted = ($boleto['email_sent'] ?? false) === true;
    $tinyOrderId = trim((string)($order['tiny_order_id'] ?? ''));
    $tinyPush = trim((string)($order['tiny_push'] ?? ''));

    clearstatcache(true, $path);
    $afterHash = hash_file('sha256', $path);
    if ($afterHash === false) {
        throw new RuntimeException('order_hash_unavailable_after_read');
    }

    $result = [
        'ok' => false,
        'mode' => 'reconcile_only',
        'reconciliation_only' => true,
        'side_effects_attempted' => false,
        'order_number' => $orderNumber,
        'payment_method' => (string)($order['payment_method'] ?? ''),
        'payment_status' => (string)($order['status'] ?? ''),
        'provider_status' => (string)($provider['status'] ?? ''),
        'persisted_provider_order' => $persistedProviderOrder,
        'digitable_line_present' => $digitableLinePresent,
        'email_send_state_persisted' => $emailStatePersisted,
        'tiny_not_imported_while_unpaid' => $tinyOrderId === '' && $tinyPush === '',
        'do_not_fulfill' => ($order['do_not_fulfill'] ?? false) === true,
        'order_file_unchanged' => hash_equals($beforeHash, $afterHash),
        'schema' => (string)($order['recovery_schema'] ?? $provider['schema'] ?? ''),
    ];

    $required = [
        'persisted_provider_order',
        'digitable_line_present',
        'email_send_state_persisted',
        'tiny_not_imported_while_unpaid',
        'do_not_fulfill',
        'order_file_unchanged',
    ];
    $missing = array_values(array_filter(
        $required,
        static fn(string $key): bool => ($result[$key] ?? false) !== true
    ));

    if ($missing !== []) {
        $result['error'] = 'ambiguous_state_retry_forbidden';
        $result['missing_assertions'] = $missing;
        reconciliation_output($result, 1);
    }

    $result['ok'] = true;
    reconciliation_output($result, 0);
} catch (Throwable $exception) {
    reconciliation_output([
        'ok' => false,
        'order_number' => $orderNumber,
        'error' => $exception->getMessage(),
        'reconciliation_only' => true,
        'side_effects_attempted' => false,
    ], 1);
} finally {
    flock($handle, LOCK_UN);
    fclose($handle);
}
