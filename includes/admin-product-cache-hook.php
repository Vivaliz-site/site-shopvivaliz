<?php
/**
 * Cache invalidation hook for product status changes
 * Triggered when admin marks product as inactive or changes is_published flag
 */
declare(strict_types=1);

function sv_admin_invalidate_product_cache(): void
{
    // Check if this is a product status update from admin
    if (!isset($_POST['action']) ||
        (!str_contains($_POST['action'], 'update_product') &&
         !str_contains($_POST['action'], 'toggle_status'))) {
        return;
    }

    // Get product ID
    $productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    if ($productId <= 0) {
        return;
    }

    // Check if status or is_published changed to inactive
    $isInactive = (isset($_POST['status']) && $_POST['status'] !== 'A' && $_POST['status'] !== 'ACTIVE') ||
                  (isset($_POST['is_published']) && $_POST['is_published'] !== '1' && $_POST['is_published'] !== true);

    if (!$isInactive) {
        return; // Only clear cache if product becomes inactive
    }

    sv_admin_clear_product_cache();
}

function sv_admin_clear_product_cache(): void
{
    // This catalog contains validated prices from Amazon table 991 in Olist.
    // An admin-side status update is NOT an authoritative ERP notification.
    // Deleting the live catalog used to take every priced product offline
    // until the next reconciliation. Keep it intact and rely on the official
    // ERP webhook/reconciliation to publish changes with the shared lock.
    error_log('[sv-cache] Admin status changed; authoritative ERP Amazon catalog preserved');

    // Only invalidate derived/public page caches if configured
    sv_admin_purge_cloudflare();
}

function sv_admin_purge_cloudflare(): void
{
    $cfZoneId = getenv('CLOUDFLARE_ZONE_ID');
    $cfToken = getenv('CLOUDFLARE_API_TOKEN');

    if (!$cfZoneId || !$cfToken) {
        return; // Cloudflare not configured
    }

    $urls = [
        'https://shopvivaliz.com.br/',
        'https://shopvivaliz.com.br/catalogo',
    ];

    foreach ($urls as $url) {
        $ch = curl_init('https://api.cloudflare.com/client/v4/zones/' . $cfZoneId . '/purge_cache');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $cfToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode(['files' => [$url]]),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            error_log('[sv-cache] Cloudflare cache cleared for: ' . $url);
        }
    }
}

// Hook into admin product update
if (strpos($_SERVER['REQUEST_URI'] ?? '', '/admin/') !== false) {
    add_action('admin_init', 'sv_admin_invalidate_product_cache', 1);
}
