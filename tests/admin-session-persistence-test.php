<?php
declare(strict_types=1);

$root = (string)(getenv('SHOPVIVALIZ_TEST_ROOT') ?: dirname(__DIR__));
$_SERVER['REQUEST_URI'] = '/admin/ai-squad.php';
$_SERVER['SCRIPT_NAME'] = '/admin/ai-squad.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$_SESSION['user_id'] = 1;
$_SESSION['is_admin'] = 1;
$_SESSION['issued_at'] = time() - 7200;

$completed = false;
register_shutdown_function(static function () use (&$completed): void {
    if (!$completed) {
        fwrite(STDERR, "admin session older than one hour was rejected\n");
        exit(1);
    }
});

require $root . '/includes/admin-guard.php';

$completed = true;
echo "ADMIN_SESSION_PERSISTENCE_TEST=PASS\n";
