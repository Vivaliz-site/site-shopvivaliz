<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/pdo-database.php';

$migration = dirname(__DIR__) . '/migrations/2026-08-02-storefront-hardening.sql';
if (!is_file($migration) || !is_readable($migration)) {
    fwrite(STDERR, "Migration file is missing or unreadable\n");
    exit(1);
}

$sql = (string)file_get_contents($migration);
$sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
$statements = preg_split('/;\s*(?:\R|$)/', $sql) ?: [];
$statements = array_values(array_filter(array_map('trim', $statements)));
if ($statements === []) {
    fwrite(STDERR, "Migration contains no executable statements\n");
    exit(1);
}

try {
    $pdo = sv_pdo();
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    $requiredTables = [
        'inventory_reservation_locks',
        'inventory_reservations',
        'newsletter_subscriptions',
    ];
    $verify = $pdo->prepare(
        'SELECT COUNT(*)
           FROM information_schema.tables
          WHERE table_schema = DATABASE()
            AND table_name = :table_name'
    );
    foreach ($requiredTables as $table) {
        $verify->execute([':table_name' => $table]);
        if ((int)$verify->fetchColumn() !== 1) {
            throw new RuntimeException('Migration verification failed for ' . $table);
        }
    }

    echo json_encode([
        'ok' => true,
        'migration' => basename($migration),
        'statements' => count($statements),
        'tables' => $requiredTables,
        'checked_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, '[migration] storefront hardening failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
