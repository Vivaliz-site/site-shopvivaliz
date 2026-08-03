<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/email-marketing-service.php';

try {
    $result = svem_send_product_highlights(sv_pdo());
    echo json_encode($result + ['executed_at' => gmdate('c')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if (($result['ok'] ?? false) !== true) {
        exit(1);
    }
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
