<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/pdo-database.php';

$path = dirname(__DIR__) . '/migrations/2026-08-03-real-email-campaigns.sql';
$sql = file_get_contents($path);
if (!is_string($sql) || trim($sql) === '') {
    fwrite(STDERR, "migration_file_unavailable\n");
    exit(1);
}

try {
    $pdo = sv_pdo();
    $pdo->exec($sql);
    foreach (['email_marketing_suppressions', 'email_campaign_runs', 'email_campaign_deliveries'] as $table) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name');
        $stmt->execute([':table_name' => $table]);
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new RuntimeException('migration_table_missing:' . $table);
        }
    }
    echo json_encode(['ok' => true, 'migration' => basename($path)], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
