<?php
/**
 * CLI/Cron worker to retry failed or pending order email notifications.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Access denied. CLI only.\n";
    exit(1);
}

require_once __DIR__ . '/../config/bootstrap-env.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/OrderNotificationService.class.php';

try {
    echo "[" . date('Y-m-d H:i:s') . "] Starting email retry worker...\n";
    $service = OrderNotificationService::getInstance();
    $count = $service->retryPendingNotifications();
    echo "[" . date('Y-m-d H:i:s') . "] Finished. Retried and sent $count emails.\n";
    exit(0);
} catch (Throwable $e) {
    error_log("[retry-failed-emails.php] Critical Error: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
