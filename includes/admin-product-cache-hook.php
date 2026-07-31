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
    $cacheFile = dirname(__DIR__) . '/storage/products-cache-ativos.json';

    if (is_file($cacheFile)) {
        @unlink($cacheFile);
        error_log('[sv-cache] Product cache cleared due to status change: ' . date('Y-m-d H:i:s'));
    }

    // Clear Cloudflare cache if configured
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
