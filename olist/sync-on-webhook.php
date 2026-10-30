<?php
declare(strict_types=1);

/**
 * Historical CLI entrypoint retained for automation compatibility.
 * Previously wrote base/catalog-list prices, which could overwrite the
 * authoritative Amazon ERP list. Only the canonical Olist public-api/v3
 * reconciliation daemon may publish the active catalog now.
 */
header('Content-Type: application/json; charset=UTF-8');
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'cli_only']);
    exit;
}
$root = dirname(__DIR__);
$daemon = $root . '/daemon-sync-products.py';
if (!is_file($daemon)) {
    fwrite(STDERR, "canonical_catalog_daemon_missing\n");
    exit(1);
}
$command = 'cd ' . escapeshellarg($root) . ' && /usr/bin/python3 '
    . escapeshellarg($daemon) . ' --once --workers 4';
passthru($command, $code);
exit((int)$code);
