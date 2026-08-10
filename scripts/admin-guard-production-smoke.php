<?php
declare(strict_types=1);

// CLI-only production smoke: validates the real ShopVivaliz admin database
// fallback without credentials or exposing account details.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../includes/pdo-database.php';

try {
    $pdo = sv_pdo();
    if (!$pdo instanceof PDO) {
        fwrite(STDERR, "Production database unavailable\n");
        exit(2);
    }

    $canonicalEmail = 'admin@shopvivaliz.com.br';
    $canonical = $pdo->prepare(
        'SELECT id, is_admin FROM users WHERE LOWER(email) = :email LIMIT 1'
    );
    $canonical->execute([':email' => $canonicalEmail]);
    $user = $canonical->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['id'])) {
        $domain = $pdo->prepare(
            "SELECT id, is_admin
               FROM users
              WHERE LOWER(email) LIKE :domain
              ORDER BY id ASC
              LIMIT 2"
        );
        $domain->execute([':domain' => '%@shopvivaliz.com.br']);
        $accounts = $domain->fetchAll(PDO::FETCH_ASSOC);
        if (count($accounts) !== 1 || empty($accounts[0]['id'])) {
            fwrite(STDERR, "Unable to resolve one persisted ShopVivaliz admin identity\n");
            exit(3);
        }
        $user = $accounts[0];
    }

    if ((int)($user['is_admin'] ?? 0) !== 1) {
        fwrite(STDERR, "Resolved ShopVivaliz identity is not marked as admin\n");
        exit(4);
    }

    $sessionDir = sys_get_temp_dir() . '/shopvivaliz-admin-smoke-sessions';
    if (!is_dir($sessionDir) && !mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
        fwrite(STDERR, "Unable to create isolated session directory\n");
        exit(5);
    }

    session_save_path($sessionDir);
    session_id('adminsmoke' . getmypid());
    session_start();
    $_SESSION = [
        'user_id' => (int)$user['id'],
        'issued_at' => time(),
    ];
    $_SERVER['REQUEST_URI'] = '/admin/__production_smoke__';

    ob_start();
    require __DIR__ . '/../includes/admin-guard.php';
    $guardOutput = trim((string)ob_get_clean());

    if ($guardOutput !== '' || (int)($_SESSION['is_admin'] ?? 0) !== 1) {
        fwrite(STDERR, "Admin guard did not authorize resolved ShopVivaliz admin identity\n");
        exit(6);
    }

    session_write_close();
    echo "ADMIN_GUARD_OK\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Admin guard smoke failed\n");
    exit(7);
}
