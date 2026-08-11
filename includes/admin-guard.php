<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/pdo-database.php';
require_once __DIR__ . '/../core/logger/logger.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (empty($_SESSION['user_id'])) {
    header('Location: /auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/admin/'));
    exit;
}

$sessionMaxAge = 3600;
$sessionIssuedAt = (int)($_SESSION['issued_at'] ?? $_SESSION['login_at'] ?? 0);
if ($sessionIssuedAt > 0 && (time() - $sessionIssuedAt) > $sessionMaxAge) {
    sv_log('admin_guard_session_expired', 'security', [
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
    ]);
    session_unset();
    session_destroy();
    header('Location: /auth/login.php?expired=1&redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/admin/'));
    exit;
}

if (empty($_SESSION['is_admin']) || !is_numeric($_SESSION['is_admin'])) {
    $isAdmin = false;
    $adminResolved = false;
    $userId = (int)$_SESSION['user_id'];

    // Prefer the same canonical mysqli connection used by auth/login.php.
    // The previous if/elseif ordering always selected sv_pdo() because the
    // function is defined by the required file above, leaving this path dead.
    // If the primary lookup cannot run, retry through PDO before denying.
    try {
        if (class_exists('Database')) {
            try {
                $conn = Database::getInstance()->getConnection();
                if ($conn instanceof mysqli) {
                    $stmt = $conn->prepare('SELECT is_admin FROM users WHERE id = ? LIMIT 1');
                    if ($stmt) {
                        $stmt->bind_param('i', $userId);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $row = $result ? $result->fetch_assoc() : null;
                        $stmt->close();
                        if (is_array($row)) {
                            $isAdmin = !empty($row['is_admin']);
                            $adminResolved = true;
                        }
                    }
                }
            } catch (Throwable $e) {
                sv_log('admin_guard_mysqli_error', 'security', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!$adminResolved && function_exists('sv_pdo')) {
            $db = sv_pdo();
            if ($db instanceof PDO) {
                $stmt = $db->prepare('SELECT is_admin FROM users WHERE id = ? LIMIT 1');
                $stmt->execute([$userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    $isAdmin = !empty($row['is_admin']);
                    $adminResolved = true;
                }
            }
        }
    } catch (Throwable $e) {
        sv_log('admin_guard_error', 'security', [
            'user_id' => $userId,
            'error' => $e->getMessage(),
        ]);
        $isAdmin = false;
    }

    $_SESSION['is_admin'] = $isAdmin ? 1 : 0;
    if (!$isAdmin) {
        sv_log('admin_guard_denied', 'security', [
            'user_id' => $userId,
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'admin_resolved' => $adminResolved,
        ]);
    }
}

if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Acesso negado.';
    exit;
}

$svAiRoutineUiPages = [
    '/admin/ai-image-studio/admin_dashboard.php',
    '/admin/ai-image-studio/admin_validate.php',
    '/admin/catalog-optimization/admin_catalog.php',
];
$svAdminScriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
if (!isset($_GET['ajax']) && in_array($svAdminScriptName, $svAiRoutineUiPages, true)) {
    register_shutdown_function(static function () use ($svAdminScriptName): void {
        $svAssetVersion = static function (string $relativePath): string {
            $path = dirname(__DIR__) . $relativePath;
            $mtime = is_file($path) ? (int)filemtime($path) : 0;
            return $mtime > 0 ? (string)$mtime : '20260811a';
        };
        echo "\n<script src=\"/admin/assets/ai-routines-hotfix-ui.js?v=" . $svAssetVersion('/admin/assets/ai-routines-hotfix-ui.js') . "\"></script>\n";
        if ($svAdminScriptName === '/admin/catalog-optimization/admin_catalog.php') {
            echo "<script src=\"/admin/assets/catalog-resilient-run-hotfix.js?v=" . $svAssetVersion('/admin/assets/catalog-resilient-run-hotfix.js') . "\"></script>\n";
            echo "<script src=\"/admin/assets/catalog-candidate-race-guard.js?v=" . $svAssetVersion('/admin/assets/catalog-candidate-race-guard.js') . "\"></script>\n";
        }
    });
}
