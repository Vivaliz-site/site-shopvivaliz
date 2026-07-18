<?php
declare(strict_types=1);

/**
 * 📦 Tiny ERP Order Fallback Sync Queue
 * Scans storage/orders for paid orders that failed to sync with Tiny ERP and retries the sync.
 */

require_once __DIR__ . '/../includes/catalog-runtime.php';
require_once __DIR__ . '/../includes/tiny-order-push.php';
require_once __DIR__ . '/../includes/mercadopago-gateway.php';

$ordersDir = __DIR__ . '/../storage/orders';
if (!is_dir($ordersDir)) {
    echo "Directory storage/orders not found.\n";
    exit(0);
}

$files = glob($ordersDir . '/*.json');
if (!is_array($files)) {
    echo "No order files found.\n";
    exit(0);
}

if (!svtop_tiny_credentials_configured()) {
    echo "Tiny credentials not configured.\n";
    exit(0);
}

echo "Starting Tiny ERP order fallback sync check...\n";

$processed = 0;
$successful = 0;

foreach ($files as $file) {
    $orderNumber = basename($file, '.json');
    if (!svmp_order_number_is_valid($orderNumber)) {
        continue;
    }

    $handle = fopen($file, 'r+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        continue;
    }

    try {
        $content = file_get_contents($file);
        $order = json_decode((string)$content, true);
        if (!is_array($order)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            continue;
        }

        $status = $order['status'] ?? '';
        $tinyOrderId = $order['tiny_order_id'] ?? '';
        $tinyPush = $order['tiny_push'] ?? '';

        if ($status === 'payment_approved' && $tinyOrderId === '') {
            $processed++;
            echo "Retrying push for order $orderNumber (Current tiny_push: $tinyPush)...\n";
            $newTinyId = svtop_push_order_tiny($order);
            if ($newTinyId) {
                $successful++;
                $order['tiny_order_id'] = $newTinyId;
                $order['tiny_push'] = 'ok';
                $order['tiny_push_retried_at'] = date(DATE_ATOM);
                
                $encoded = json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, $encoded);
                fflush($handle);
                echo "Successfully pushed order $orderNumber to Tiny. Tiny ID: $newTinyId\n";
            } else {
                echo "Push failed for order $orderNumber.\n";
            }
        }
    } catch (Throwable $e) {
        echo "Error syncing order $orderNumber: " . $e->getMessage() . "\n";
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

echo "Fallback sync check finished. Processed: $processed, Successful: $successful.\n";
