<?php
declare(strict_types=1);

// CLI-only production smoke: validates the real admin database fallback without credentials.
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

    // Do not hard-code an invented administrative identity. The authorization
    // contract is role-based: any persisted user with is_admin=1 must be
    // recognized by admin-guard.php when the session only carries user_id.
    // No email/name/password is read or printed by this smoke test.
    $stmt = $pdo->query('SELECT id FROM users WHERE is_admin = 1 ORDER BY id ASC LIMIT 1');
    $user = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

    if (!$user || empty($user['id'])) {
        fwrite(STDERR, "No persisted administrative user is available\n");
        exit(3);
    }

    $sessionDir = sys_get_temp_dir() . '/shopvivaliz-admin-smoke-sessions';
    if (!is_dir($sessionDir) && !mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
        fwrite(STDERR, "Unable to create isolated session directory\n");
        exit(4);
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
        fwrite(STDERR, "Admin guard did not authorize persisted administrative user\n");
        exit(5);
    }

    session_write_close();
    echo "ADMIN_GUARD_OK\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Admin guard smoke failed\n");
    exit(6);
}
