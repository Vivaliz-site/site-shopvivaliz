<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/inventory-reservations.php';

try {
    $pdo = sv_pdo();
    $result = svir_reconcile($pdo);
    $active = (int)$pdo->query(
        "SELECT COUNT(*) FROM inventory_reservations
         WHERE status IN ('active', 'consumed')
           AND expires_at > NOW()"
    )->fetchColumn();

    echo json_encode([
        'ok' => true,
        'checked_at' => date(DATE_ATOM),
        'active_or_consumed' => $active,
        'changes' => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, '[inventory] reconciliation failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
