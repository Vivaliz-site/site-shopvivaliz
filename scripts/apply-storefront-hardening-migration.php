<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__);
$projectParent = dirname($projectRoot);
$deployRoot = basename($projectParent) === 'releases'
    ? dirname($projectParent)
    : $projectParent;
$sharedEnv = $deployRoot . '/shared/.env';
$sharedRuntime = $deployRoot . '/shared/runtime-secrets.php';
$materializer = $projectRoot . '/scripts/materialize-runtime-secrets.php';

// Deploys de releases novas executam esta migracao antes da reconciliacao
// final do runtime. Materialize e carregue o tuple seguro de banco primeiro
// para impedir fallback acidental para root@localhost.
if (is_file($sharedEnv) && is_readable($sharedEnv) && is_file($materializer) && is_readable($materializer)) {
    putenv('SHOPVIVALIZ_SHARED_ENV=' . $sharedEnv);
    putenv('SHOPVIVALIZ_RUNTIME_SECRETS=' . $sharedRuntime);
    require $materializer;
}

if (is_file($sharedRuntime) && is_readable($sharedRuntime)) {
    $runtimeValues = require $sharedRuntime;
    if (is_array($runtimeValues)) {
        foreach ($runtimeValues as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'DB_') || !is_scalar($value)) {
                continue;
            }
            $stringValue = trim((string)$value);
            if ($stringValue === '') {
                continue;
            }
            putenv($key . '=' . $stringValue);
            $_ENV[$key] = $stringValue;
            $_SERVER[$key] = $stringValue;
        }
    }
}

require_once $projectRoot . '/includes/pdo-database.php';

$migration = $projectRoot . '/migrations/2026-08-02-storefront-hardening.sql';
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
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection unavailable');
    }
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
