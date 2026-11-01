<?php
declare(strict_types=1);

/**
 * Retired installer: previously rewrote the canonical Olist webhook endpoint
 * to include an unauthenticated legacy MySQL price/stock processor.
 *
 * Canonical Olist v3 ingress is /api/olist/webhook.php and must never be
 * patched by a web-executable installer. No files or services are modified.
 */
header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(410);
echo json_encode(['ok' => false, 'error' => 'legacy_setup_retired']);
exit;
