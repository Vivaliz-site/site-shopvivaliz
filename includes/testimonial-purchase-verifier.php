<?php
declare(strict_types=1);

function svtpv_order_reference(string $value): string
{
    $value = strtoupper(trim($value));
    return preg_match('/^SV[0-9A-Z_-]{8,40}$/', $value) === 1 ? $value : '';
}

function svtpv_is_confirmed_status(string $status): bool
{
    $status = strtolower(trim($status));
    return in_array($status, [
        'payment_approved', 'paid', 'approved', 'payment_received', 'processing',
        'faturado', 'enviado', 'shipped', 'delivered', 'completed', 'concluido',
        'concluído', 'nota_fiscal_enviada',
    ], true);
}

function svtpv_verify(string $orderReference, string $email, string $sku = ''): bool
{
    $orderReference = svtpv_order_reference($orderReference);
    $email = strtolower(trim($email));
    if ($orderReference === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    $root = dirname(__DIR__);
    $paths = [
        $root . '/storage/orders/' . $orderReference . '.json',
        rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/shopvivaliz-orders/' . $orderReference . '.json',
    ];
    $order = [];
    foreach ($paths as $path) {
        if (!is_file($path) || !is_readable($path)) continue;
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded)) {
            $order = $decoded;
            break;
        }
    }
    if ($order === []) return false;

    $storedReference = strtoupper(trim((string)($order['order_number'] ?? $order['id'] ?? '')));
    $storedEmail = strtolower(trim((string)($order['customer']['email'] ?? $order['email'] ?? $order['customer_email'] ?? '')));
    $status = (string)($order['status'] ?? $order['order_status'] ?? $order['payment_status'] ?? '');
    $providerStatus = (string)($order['mercadopago']['status'] ?? $order['infinitepay']['status'] ?? '');
    if (!hash_equals($orderReference, $storedReference) || !hash_equals($storedEmail, $email)) return false;
    if (!svtpv_is_confirmed_status($status) && !svtpv_is_confirmed_status($providerStatus)) return false;

    $sku = strtolower(trim($sku));
    if ($sku === '') return true;
    foreach (($order['items'] ?? []) as $item) {
        if (is_array($item) && hash_equals($sku, strtolower(trim((string)($item['sku'] ?? ''))))) return true;
    }
    return false;
}
