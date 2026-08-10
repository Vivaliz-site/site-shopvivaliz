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
    $stmt = $pdo->prepare('SELECT id, is_admin FROM users WHERE email = ? LIMIT 1');
    $stmt->execute(['admin@shopvivaliz.com.br']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['id']) || empty($user['is_admin'])) {
        fwrite(STDERR, "Canonical admin account missing or not marked as admin\n");
        exit(2);
    }

    $sessionDir = sys_get_temp_dir() . '/shopvivaliz-admin-smoke-sessions';
    if (!is_dir($sessionDir) && !mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
        fwrite(STDERR, "Unable to create isolated session directory\n");
        exit(3);
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
        fwrite(STDERR, "Admin guard did not authorize canonical admin\n");
        exit(4);
    }

    session_write_close();
    echo "ADMIN_GUARD_OK\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Admin guard smoke failed\n");
    exit(5);
}
