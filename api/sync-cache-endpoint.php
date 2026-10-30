<?php
declare(strict_types=1);

/**
 * Retired unsafe catalog rebuild endpoint.
 *
 * Previously a public GET/POST could replace the live catalog with an
 * incompletely enriched ERP list, using a lock different from the webhook
 * and periodic daemon. Never expose catalog mutation by an HTTP endpoint.
 *
 * Authorized operators use the canonical systemd catalog reconciliation
 * service, which resolves the Amazon ERP list and applies a shared lock.
 */
header_remove('X-Powered-By');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Allow: POST');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

http_response_code(410);
echo json_encode([
    'success' => false,
    'error' => 'legacy_catalog_rebuild_retired',
    'source_of_truth' => 'olist_tiny_amazon',
    'reconcile_service' => 'shopvivaliz-catalog-reconcile.service',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
