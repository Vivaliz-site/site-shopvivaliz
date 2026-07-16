<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__) . '/includes/catalog-runtime.php';

$products = svcr_products();
$total = count($products);
$skus = [];
$images = 0;
$priced = 0;
$available = 0;

foreach ($products as $product) {
    if (!is_array($product)) continue;
    $sku = trim((string)($product['sku'] ?? ''));
    if ($sku !== '') $skus[$sku] = true;
    $image = trim((string)($product['image_url'] ?? ''));
    if (preg_match('~^https://~i', $image)) $images++;
    if ((float)($product['price'] ?? 0) > 0) $priced++;
    if ((int)($product['stock'] ?? 0) > 0) $available++;
}

$root = dirname(__DIR__);
$checks = [
    'catalog_products' => ['pass' => $total >= 180, 'actual' => $total, 'expected' => '>= 180'],
    'unique_skus' => ['pass' => count($skus) === $total, 'actual' => count($skus), 'expected' => $total],
    'valid_prices' => ['pass' => $priced === $total, 'actual' => $priced, 'expected' => $total],
    'products_in_stock' => ['pass' => $available > 0, 'actual' => $available, 'expected' => '> 0'],
    'real_image_coverage' => [
        'pass' => $total > 0 && ($images / $total) >= 0.98,
        'actual' => $total > 0 ? round(($images / $total) * 100, 2) . '%' : '0%',
        'expected' => '>= 98%',
    ],
    'atomic_sync_daemon' => ['pass' => is_file($root . '/daemon-sync-products.py'), 'actual' => 'file', 'expected' => 'present'],
    'catalog_runtime' => ['pass' => is_file($root . '/includes/catalog-runtime.php'), 'actual' => 'file', 'expected' => 'present'],
    'csrf_guard' => ['pass' => is_file($root . '/includes/csrf.php'), 'actual' => 'file', 'expected' => 'present'],
    'apache_private_policy' => ['pass' => is_file($root . '/deploy/apache/shopvivaliz-private-paths.conf'), 'actual' => 'file', 'expected' => 'present'],
    'token_renewer_service' => ['pass' => is_file($root . '/deploy/systemd/shopvivaliz-token-renewer.service'), 'actual' => 'file', 'expected' => 'present'],
    'atomic_env_sync' => ['pass' => is_file($root . '/scripts/update-production-env.py'), 'actual' => 'file', 'expected' => 'present'],
    'mercadopago_gateway' => ['pass' => is_file($root . '/includes/mercadopago-gateway.php'), 'actual' => 'file', 'expected' => 'present'],
    'mercadopago_boleto_endpoint' => ['pass' => is_file($root . '/api/mercadopago/create-boleto.php'), 'actual' => 'file', 'expected' => 'present'],
    'mercadopago_preference_endpoint' => ['pass' => is_file($root . '/api/mercadopago/create-preference.php'), 'actual' => 'file', 'expected' => 'present'],
    'mercadopago_signed_webhook' => ['pass' => str_contains((string)@file_get_contents($root . '/api/webhook-mercadopago.php'), 'svmp_validate_webhook_signature'), 'actual' => 'signature validation', 'expected' => 'present'],
];

$ok = !in_array(false, array_column($checks, 'pass'), true);
http_response_code($ok ? 200 : 503);

echo json_encode([
    'ok' => $ok,
    'status' => $ok ? '100% OK' : 'FAILURES DETECTED',
    'version' => '9.2.104',
    'checks' => $checks,
    'timestamp' => date(DATE_ATOM),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
