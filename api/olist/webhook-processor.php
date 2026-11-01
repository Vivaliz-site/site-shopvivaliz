<?php
declare(strict_types=1);

/**
 * RETIRED legacy endpoint: this file previously accepted unauthenticated
 * caller-controlled product price/stock/delete events and wrote directly to
 * the local MySQL products table. Do not restore those handlers.
 *
 * The ONLY supported Olist ingress is /api/olist/webhook.php, whose provider
 * authentication and Olist/Tiny v3 reconciliation are enforced independently.
 * Also denied at the web-server boundary by .htaccess (defense in depth).
 */
header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
http_response_code(410);
echo json_encode(['ok' => false, 'error' => 'legacy_webhook_retired']);
exit;
