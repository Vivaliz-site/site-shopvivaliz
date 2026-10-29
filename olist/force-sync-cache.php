<?php
declare(strict_types=1);

/**
 * Legacy manual refresh kept as a CLI alias to the only approved writer.
 * HTTP-driven rebuilds are forbidden: use the canonical systemd service
 * for an authorized manual run or its 30-minute timer.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'cli_only']);
    exit;
}
require __DIR__ . '/sync-on-webhook.php';
