<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$script = $root . '/scripts/quality/audit-product-image-mismatch.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_file($script)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'endpoint' => 'product-image-mismatch-audit',
        'error' => 'audit_script_missing',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$limit = isset($_GET['limit']) ? max(1, min(2000, (int)$_GET['limit'])) : 500;

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg((string)$limit);
$output = [];
$status = 0;
exec($cmd, $output, $status);
$body = trim(implode("\n", $output));

if ($status !== 0 || $body === '') {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'endpoint' => 'product-image-mismatch-audit',
        'error' => 'audit_failed',
        'status' => $status,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$decoded = json_decode($body, true);
if (!is_array($decoded)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'endpoint' => 'product-image-mismatch-audit',
        'error' => 'invalid_audit_json',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$decoded['endpoint'] = 'product-image-mismatch-audit';
$decoded['generated_at'] = date('c');

echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
