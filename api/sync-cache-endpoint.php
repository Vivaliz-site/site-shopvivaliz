<?php
declare(strict_types=1);

/**
 * The old HTTP cache-sync handler copied registration prices directly to the
 * storefront and could overwrite a previously verified PDV catalog. Disabled
 * permanently: only the privileged scheduled/authorized PDV reconciler may
 * publish an atomic, fully validated product snapshot under its shared lock.
 * An HTTP caller must not spawn a privileged synchronizer.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
http_response_code(410);
echo json_encode([
    'ok' => false,
    'error' => 'legacy_cache_sync_disabled',
    'required_source' => 'olist_pdv',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
