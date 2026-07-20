<?php

declare(strict_types=1);

// Bootstrap .env early so auth can resolve runtime secrets on Apache/FPM and CLI.
(static function (): void {
    $envFile = dirname(__DIR__, 2) . '/.env';
    if (!is_file($envFile)) {
        return;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), '"\'');
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_SERVER[$key] = $value;
        }
    }
})();

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function svaw_reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function svaw_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$serverKey] ?? ''));
}

function svaw_expected_key(): string
{
    foreach (['SHOPVIVALIZ_AGENT_KEY', 'AGENT_KEY', 'WATCHDOG_AGENT_KEY', 'AUTONOMOUS_AGENT_KEY', 'CRON_SECRET', 'APP_KEY', 'SHOPVIVALIZ_APP_KEY'] as $name) {
        $value = getenv($name);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        if (isset($_SERVER[$name]) && trim((string)$_SERVER[$name]) !== '') {
            return trim((string)$_SERVER[$name]);
        }
    }
    return '';
}

function svaw_provided_key(): string
{
    foreach ([
        svaw_header('X-ShopVivaliz-Agent-Key'),
        svaw_header('X-Agent-Key'),
        trim((string)($_GET['agent_key'] ?? '')),
        trim((string)($_POST['agent_key'] ?? '')),
        trim((string)($_GET['secret'] ?? '')),
        trim((string)($_POST['secret'] ?? '')),
    ] as $candidate) {
        if ($candidate !== '') {
            return $candidate;
        }
    }
    return '';
}

function svaw_is_local_request(): bool
{
    if (PHP_SAPI === 'cli-server') {
        return true;
    }

    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $serverName = trim((string)($_SERVER['SERVER_NAME'] ?? ''));

    return str_contains($remote, '127.0.0.1')
        || str_contains($remote, '::1')
        || $remote === 'localhost'
        || str_contains($host, '127.0.0.1')
        || str_contains($host, 'localhost')
        || str_contains($serverName, '127.0.0.1')
        || str_contains($serverName, 'localhost');
}

function svaw_pdo(): ?PDO
{
    static $pdo = false;
    if ($pdo instanceof PDO) return $pdo;

    $host = getenv('DB_HOST') ?: (defined('DB_HOST') ? (string)DB_HOST : '');
    $name = getenv('DB_NAME') ?: (defined('DB_NAME') ? (string)DB_NAME : '');
    $user = getenv('DB_USER') ?: (defined('DB_USER') ? (string)DB_USER : '');
    $pass = getenv('DB_PASS') ?: (defined('DB_PASS') ? (string)DB_PASS : '');
    $port = getenv('DB_PORT') ?: (defined('DB_PORT') ? (string)DB_PORT : '3306');
    if ($host === '' || $name === '' || $user === '') return null;

    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        return $pdo;
    } catch (Throwable $ignored) {
        return null;
    }
}

$expectedKey = svaw_expected_key();
if ($expectedKey === '' && !svaw_is_local_request()) {
    svaw_reply(503, ['ok' => false, 'agent' => 'autonomous_watchdog', 'error' => 'agent_key_not_configured']);
}

$providedKey = svaw_provided_key();
if ($expectedKey !== '' && ($providedKey === '' || !hash_equals($expectedKey, $providedKey))) {
    svaw_reply(401, ['ok' => false, 'agent' => 'autonomous_watchdog', 'error' => 'unauthorized']);
}

$root = dirname(__DIR__, 2);
$constants = $root . '/config/constants.php';
if (is_file($constants)) require_once $constants;
require_once $root . '/includes/pdo-database.php';
require_once $root . '/agents/v9.2.84/app/AutonomousWatchdogAgent.php';

// Adapter consumed by the resident agents. It intentionally returns only PDO.
if (!function_exists('sv_pdo')) {
    function sv_pdo(): ?PDO { return svaw_pdo(); }
}

$body = file_get_contents('php://input');
$input = json_decode(is_string($body) ? $body : '', true);
if (!is_array($input)) $input = [];
$options = [
    'run_loop' => (bool)($input['run_loop'] ?? $_POST['run_loop'] ?? $_GET['run_loop'] ?? true),
    'cycles' => max(1, min(10, (int)($input['cycles'] ?? $_POST['cycles'] ?? $_GET['cycles'] ?? 1))),
    'chunk_size' => max(1, min(25, (int)($input['chunk_size'] ?? $_POST['chunk_size'] ?? $_GET['chunk_size'] ?? 10))),
    'image_limit' => max(1, min(500, (int)($input['image_limit'] ?? $_POST['image_limit'] ?? $_GET['image_limit'] ?? 100))),
];

try {
    $result = (new ShopvivalizAutonomousWatchdogAgent())->run($options);
    $loop = $result['steps']['loop_starter'] ?? null;
    $executed = ($result['ok'] ?? false) === true
        && is_array($loop)
        && ($loop['ok'] ?? false) === true
        && in_array((string)($loop['status'] ?? ''), ['queued', 'degraded'], true);
    $result['execution_accepted'] = $executed;
    svaw_reply($executed ? 202 : 500, $result);
} catch (Throwable $e) {
    error_log('autonomous-watchdog failure: ' . $e->getMessage());
    svaw_reply(500, ['ok' => false, 'agent' => 'autonomous_watchdog', 'error' => 'execution_failed']);
}
