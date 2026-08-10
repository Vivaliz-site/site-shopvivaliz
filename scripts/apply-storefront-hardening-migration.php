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

    // Reconcile one persisted ShopVivaliz administrative identity as part of
    // the existing idempotent production migration hook. Never create users,
    // change passwords, or guess among multiple domain accounts.
    $adminColumn = $pdo->query(
        "SELECT COUNT(*)
           FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = 'users'
            AND column_name = 'is_admin'"
    );
    if ((int)$adminColumn->fetchColumn() !== 1) {
        $pdo->exec('ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0');
    }

    $canonicalAdminEmail = 'admin@shopvivaliz.com.br';
    $adminLookup = $pdo->prepare('SELECT id, is_admin FROM users WHERE LOWER(email) = :email LIMIT 1');
    $adminLookup->execute([':email' => $canonicalAdminEmail]);
    $admin = $adminLookup->fetch(PDO::FETCH_ASSOC);
    $adminResolution = 'canonical_email';

    if (!$admin || empty($admin['id'])) {
        $domainLookup = $pdo->prepare(
            "SELECT id, is_admin
               FROM users
              WHERE LOWER(email) LIKE :domain
              ORDER BY id ASC
              LIMIT 2"
        );
        $domainLookup->execute([':domain' => '%@shopvivaliz.com.br']);
        $domainAccounts = $domainLookup->fetchAll(PDO::FETCH_ASSOC);

        if (count($domainAccounts) === 0) {
            throw new RuntimeException('No persisted ShopVivaliz domain account is available');
        }
        if (count($domainAccounts) !== 1 || empty($domainAccounts[0]['id'])) {
            throw new RuntimeException('Multiple ShopVivaliz domain accounts exist; refusing to guess admin identity');
        }

        $admin = $domainAccounts[0];
        $adminResolution = 'single_domain_account';
    }

    $adminId = (int)$admin['id'];
    if ((int)($admin['is_admin'] ?? 0) !== 1) {
        $adminUpdate = $pdo->prepare('UPDATE users SET is_admin = 1 WHERE id = :id');
        $adminUpdate->execute([':id' => $adminId]);
    }

    $adminVerify = $pdo->prepare('SELECT is_admin FROM users WHERE id = :id LIMIT 1');
    $adminVerify->execute([':id' => $adminId]);
    if ((int)$adminVerify->fetchColumn() !== 1) {
        throw new RuntimeException('Administrative authorization verification failed');
    }

    echo json_encode([
        'ok' => true,
        'migration' => basename($migration),
        'statements' => count($statements),
        'tables' => $requiredTables,
        'admin_authorized' => true,
        'admin_resolution' => $adminResolution,
        'checked_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, '[migration] storefront hardening failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
