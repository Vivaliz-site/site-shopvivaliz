<?php
declare(strict_types=1);

$dir = sys_get_temp_dir() . '/shopvivaliz-session-persistence-' . bin2hex(random_bytes(4));
if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "unable to create temp session dir\n");
    exit(1);
}

putenv('SHOPVIVALIZ_SESSION_PATH=' . $dir);
$_ENV['SHOPVIVALIZ_SESSION_PATH'] = $dir;
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;

require __DIR__ . '/../includes/secure-session.php';

$errors = [];
$params = session_get_cookie_params();
if ((int)($params['lifetime'] ?? 0) !== 2592000) {
    $errors[] = 'cookie lifetime must be 2592000';
}
if ((int)ini_get('session.gc_maxlifetime') !== 2592000) {
    $errors[] = 'gc_maxlifetime must be 2592000';
}
if (session_save_path() !== $dir) {
    $errors[] = 'session_save_path must use SHOPVIVALIZ_SESSION_PATH';
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
@unlink($dir . '/sess_' . session_id());
@rmdir($dir);

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "SECURE_SESSION_PERSISTENCE_TEST=PASS\n";
