<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/constants.php';
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

    if (function_exists('sv_pdo')) {
        try {
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
        } catch (Throwable $e) {
            sv_log('admin_guard_pdo_error', 'security', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    if (!$adminResolved) {
        try {
            require_once __DIR__ . '/../config/database.php';

            if (class_exists('Database')) {
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
            }
        } catch (Throwable $e) {
            sv_log('admin_guard_mysqli_error', 'security', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
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

$svAdminUiPages = [
    '/admin/index.php',
    '/admin/ai-image-studio/admin_dashboard.php',
    '/admin/ai-image-studio/admin_validate.php',
    '/admin/catalog-optimization/admin_catalog.php',
];
$svAdminScriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
if (!isset($_GET['ajax']) && in_array($svAdminScriptName, $svAdminUiPages, true)) {
    register_shutdown_function(static function () use ($svAdminScriptName): void {
        $svAssetVersion = static function (string $relativePath): string {
            $path = dirname(__DIR__) . $relativePath;
            $mtime = is_file($path) ? (int)filemtime($path) : 0;
            return $mtime > 0 ? (string)$mtime : '20260813i';
        };

        if ($svAdminScriptName === '/admin/index.php') {
            echo "\n<script src=\"/admin/assets/admin-ai-overview.js?v=" . $svAssetVersion('/admin/assets/admin-ai-overview.js') . "\"></script>\n";
            echo "<script src=\"/admin/assets/admin-mobile-completion.js?v=" . $svAssetVersion('/admin/assets/admin-mobile-completion.js') . "\"></script>\n";
            return;
        }

        if ($svAdminScriptName === '/admin/catalog-optimization/admin_catalog.php') {
            echo "\n<script src=\"/admin/assets/catalog-optimization-workflow.js?v=" . $svAssetVersion('/admin/assets/catalog-optimization-workflow.js') . "\"></script>\n";
            echo "<script src=\"/admin/assets/catalog-change-intelligence.js?v=" . $svAssetVersion('/admin/assets/catalog-change-intelligence.js') . "\"></script>\n";
            echo "<script src=\"/admin/assets/catalog-effective-results.js?v=" . $svAssetVersion('/admin/assets/catalog-effective-results.js') . "\"></script>\n";
            echo "<script src=\"/admin/assets/catalog-candidate-availability.js?v=" . $svAssetVersion('/admin/assets/catalog-candidate-availability.js') . "\"></script>\n";
            echo "<script src=\"/admin/assets/admin-mobile-completion.js?v=" . $svAssetVersion('/admin/assets/admin-mobile-completion.js') . "\"></script>\n";
            return;
        }

        // Um unico controlador continua dono de selecao, fila, revisao e
        // publicacao. As camadas auxiliares somente observam, bloqueiam riscos,
        // retomam acompanhamento e oferecem diagnostico operacional; elas nunca
        // publicam nem aprovam imagens automaticamente.
        echo "\n<script src=\"/admin/assets/image-generation-workflow.js?v=" . $svAssetVersion('/admin/assets/image-generation-workflow.js') . "\"></script>\n";
        echo "<script src=\"/admin/assets/image-workflow-safety.js?v=" . $svAssetVersion('/admin/assets/image-workflow-safety.js') . "\"></script>\n";
        if ($svAdminScriptName === '/admin/ai-image-studio/admin_dashboard.php') {
            echo "<script src=\"/admin/assets/image-provider-health.js?v=" . $svAssetVersion('/admin/assets/image-provider-health.js') . "\"></script>\n";
            echo "<script src=\"/admin/assets/image-professional-ops.js?v=" . $svAssetVersion('/admin/assets/image-professional-ops.js') . "\"></script>\n";
            echo "<script src=\"/admin/assets/image-generation-usability-v2.js?v=" . $svAssetVersion('/admin/assets/image-generation-usability-v2.js') . "\"></script>\n";
        }
    });
}
