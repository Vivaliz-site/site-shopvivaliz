<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function svsa_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function svsa_root(): string
{
    return dirname(__DIR__, 2);
}

function svsa_data_dir(): string
{
    $preferred = svsa_root() . '/storage/stock-alerts';
    if ((is_dir($preferred) || @mkdir($preferred, 0755, true)) && is_writable($preferred)) {
        return $preferred;
    }

    $fallback = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'shopvivaliz-stock-alerts';
    if ((is_dir($fallback) || @mkdir($fallback, 0755, true)) && is_writable($fallback)) {
        return $fallback;
    }

    return '';
}

function svsa_request_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 20000) {
        svsa_json(413, ['ok' => false, 'error' => 'payload_too_large']);
    }

    $type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($type, 'application/json')) {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            svsa_json(400, ['ok' => false, 'error' => 'invalid_json']);
        }
        return $data;
    }

    return $_POST;
}

function svsa_normalize_sku(string $sku): string
{
    $sku = strtoupper(trim($sku));
    $sku = preg_replace('/[^A-Z0-9._-]/', '', $sku) ?: '';
    return substr($sku, 0, 80);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svsa_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$body = svsa_request_body();
$sku = svsa_normalize_sku((string)($body['sku'] ?? ''));
$email = strtolower(trim((string)($body['email'] ?? '')));
$name = trim((string)($body['name'] ?? ''));
$productName = trim((string)($body['product_name'] ?? ''));

if ($sku === '' || strlen($sku) > 80) {
    svsa_json(422, ['ok' => false, 'error' => 'invalid_sku']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) {
    svsa_json(422, ['ok' => false, 'error' => 'invalid_email']);
}
if (strlen($name) > 120 || strlen($productName) > 220) {
    svsa_json(422, ['ok' => false, 'error' => 'field_too_long']);
}

$dir = svsa_data_dir();
if ($dir === '') {
    svsa_json(500, ['ok' => false, 'error' => 'storage_unavailable']);
}

$id = hash('sha256', $sku . '|' . $email);
$file = $dir . '/subscribers.jsonl';
$exists = false;
if (is_file($file)) {
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true);
        if (is_array($row) && ($row['id'] ?? '') === $id && ($row['status'] ?? 'pending') === 'pending') {
            $exists = true;
            break;
        }
    }
}

if (!$exists) {
    $record = [
        'id' => $id,
        'sku' => $sku,
        'email' => $email,
        'name' => $name,
        'product_name' => $productName,
        'status' => 'pending',
        'created_at' => gmdate('c'),
        'source' => 'stock-alert-subscribe',
    ];
    $ok = @file_put_contents(
        $file,
        json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
    if ($ok === false) {
        svsa_json(500, ['ok' => false, 'error' => 'write_failed']);
    }
}

svsa_json(200, [
    'ok' => true,
    'subscription_id' => $id,
    'status' => $exists ? 'already_pending' : 'pending',
]);
