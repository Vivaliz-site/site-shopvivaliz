<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'ops/browser-worker/server.mjs',
    'ops/browser-worker/supervisor.sh',
    'scripts/install-backend-browser-worker.sh',
    'admin/browser-worker.php',
    '.github/workflows/backend-browser-worker-control.yml',
];

foreach ($files as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        fwrite(STDERR, "missing file: {$file}\n");
        exit(1);
    }
}

$server = file_get_contents($root . '/ops/browser-worker/server.mjs');
$supervisor = file_get_contents($root . '/ops/browser-worker/supervisor.sh');
$admin = file_get_contents($root . '/admin/browser-worker.php');
$workflow = file_get_contents($root . '/.github/workflows/backend-browser-worker-control.yml');
$install = file_get_contents($root . '/scripts/install-backend-browser-worker.sh');

$mustContain = [
    [$server, "const HOST = '127.0.0.1';", 'worker must bind loopback'],
    [$server, 'const DEFAULT_TTL = 2 * 60 * 60;', 'default TTL must be two hours'],
    [$server, "origin: session.origin", 'session origin metadata required'],
    [$server, "profile: session.profile", 'profile metadata required'],
    [$server, "setInterval(async () =>", 'TTL watchdog required'],
    [$server, "'Control+A','Control+C','Control+V'", 'safe clipboard shortcuts required'],
    [$supervisor, '-R "127.0.0.1:$SITE_REMOTE_PORT:127.0.0.1:$PORT"', 'reverse tunnel must bind site loopback'],
    [$supervisor, 'StrictHostKeyChecking=yes', 'strict host verification required'],
    [$admin, "require_once __DIR__ . '/../includes/admin-guard.php';", 'admin authentication required'],
    [$admin, "sv_csrf_valid(BW_SCOPE", 'CSRF validation required'],
    [$admin, "const BW_BASE = 'http://127.0.0.1:17777';", 'admin proxy must target loopback only'],
    [$workflow, 'ubuntu@10.0.1.38', 'workflow must use private backend address'],
    [$workflow, 'allowed = {"status", "health", "install", "start", "restart", "stop"}', 'workflow must be allowlisted'],
    [$install, '# SHOPVIVALIZ_BROWSER_WORKER_V1', 'persistent watchdog marker required'],
    [$install, 'PY_PW_VERSION="${SHOPVIVALIZ_BROWSER_PY_PLAYWRIGHT_VERSION:-${PW_VERSION%.*}.0}"', 'Python Playwright version must normalize the Node patch release'],
    [$install, '"playwright==$PY_PW_VERSION"', 'Python Playwright install must use the normalized Python version'],
];

foreach ($mustContain as [$haystack, $needle, $message]) {
    if (!str_contains((string)$haystack, $needle)) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$forbidden = [
    [$server, "listen(PORT, '0.0.0.0'", 'worker must never bind public wildcard'],
    [$server, "action === 'evaluate'", 'arbitrary browser evaluation is forbidden'],
    [$admin, 'CURLOPT_URL', 'admin must not accept arbitrary proxy destinations'],
    [$workflow, 'CMD="${{', 'workflow must not execute user-provided shell'],
    [$supervisor, 'GatewayPorts=yes', 'reverse tunnel must not enable gateway ports'],
    [$install, '"playwright==$PW_VERSION"', 'Python Playwright must not reuse the npm patch version blindly'],
];

foreach ($forbidden as [$haystack, $needle, $message]) {
    if (str_contains((string)$haystack, $needle)) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

echo "BACKEND_BROWSER_WORKER_CONTRACT=PASS\n";
