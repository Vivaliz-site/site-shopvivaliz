<?php

declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/includes/mercadopago-sandbox.php';

function svmp_sb_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svmp_sb_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$raw = (string)file_get_contents('php://input');
if ($raw === '' || strlen($raw) > 20000) {
    svmp_sb_response(400, ['ok' => false, 'error' => 'invalid_request']);
}

$input = json_decode($raw, true);
if (!is_array($input)) {
    svmp_sb_response(400, ['ok' => false, 'error' => 'invalid_json']);
}

if (svmp_sb_access_token() === '' || svmp_sb_public_key() === '') {
    svmp_sb_response(503, ['ok' => false, 'error' => 'sandbox_credentials_missing']);
}

$email = trim((string)($input['customer_email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    svmp_sb_response(422, ['ok' => false, 'error' => 'invalid_customer_email']);
}

$dir = svmp_sb_order_dir();
if ($dir === '') {
    svmp_sb_response(503, ['ok' => false, 'error' => 'sandbox_storage_unavailable']);
}

try {
    $record = svmp_sb_record($input);
    $sessionToken = (string)$record['_payment_session_token'];
    unset($record['_payment_session_token']);

    $preference = svmp_sb_create_preference($record);
    $record['status'] = 'payment_pending';
    $record['mercadopago'] = [
        'provider' => 'checkout_pro_sandbox',
        'preference' => [
            'id' => $preference['preference_id'],
            'checkout_url' => $preference['checkout_url'],
            'created_at' => date(DATE_ATOM),
        ],
    ];

    $path = svmp_sb_order_path((string)$record['order_number']);
    $encoded = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($path, $encoded, LOCK_EX) === false) {
        svmp_sb_response(500, ['ok' => false, 'error' => 'sandbox_order_write_failed']);
    }

    svmp_sb_response(201, [
        'ok' => true,
        'sandbox_mode' => true,
        'order_number' => $record['order_number'],
        'payment_session_token' => $sessionToken,
        'checkout_url' => $preference['checkout_url'],
        'public_key' => svmp_sb_public_key(),
        'status' => $record['status'],
        'total' => $record['total'],
    ]);
} catch (SvMercadoPagoApiException $e) {
    svmp_sb_response($e->httpStatus, ['ok' => false, 'error' => $e->publicCode]);
} catch (Throwable $e) {
    svmp_sb_response(500, ['ok' => false, 'error' => 'internal_error']);
}

