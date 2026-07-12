<?php

declare(strict_types=1);

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
    foreach (['SHOPVIVALIZ_AGENT_KEY', 'AGENT_KEY', 'WATCHDOG_AGENT_KEY', 'AUTONOMOUS_AGENT_KEY'] as $name) {
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
if ($expectedKey === '') {
    svaw_reply(503, ['ok' => false, 'agent' => 'autonomous_watchdog', 'error' => 'agent_key_not_configured']);
}

$providedKey = svaw_header('X-ShopVivaliz-Agent-Key');
if ($providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
    svaw_reply(401, ['ok' => false, 'agent' => 'autonomous_watchdog', 'error' => 'unauthorized']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    svaw_reply(405, ['ok' => false, 'agent' => 'autonomous_watchdog', 'error' => 'method_not_allowed']);
}

$root = dirname(__DIR__, 2);
$constants = $root . '/config/constants.php';
if (is_file($constants)) require_once $constants;
require_once $root . '/agents/v9.2.84/app/AutonomousWatchdogAgent.php';

// Adapter consumed by the resident agents. It intentionally returns only PDO.
if (!function_exists('sv_pdo')) {
    function sv_pdo(): ?PDO { return svaw_pdo(); }
}

$body = file_get_contents('php://input');
$input = json_decode(is_string($body) ? $body : '', true);
if (!is_array($input)) $input = [];
$options = [
    'run_loop' => true,
    'cycles' => max(1, min(10, (int)($input['cycles'] ?? 1))),
    'chunk_size' => max(1, min(25, (int)($input['chunk_size'] ?? 10))),
    'image_limit' => max(1, min(500, (int)($input['image_limit'] ?? 100))),
];

try {
    $result = (new ShopvivalizAutonomousWatchdogAgent())->run($options);
    $loop = $result['steps']['loop_starter'] ?? null;
    $executed = ($result['ok'] ?? false) === true
        && is_array($loop)
        && ($loop['ok'] ?? false) === true
        && ($loop['status'] ?? '') === 'queued';
    $result['execution_accepted'] = $executed;
    svaw_reply($executed ? 202 : 500, $result);
} catch (Throwable $e) {
    error_log('autonomous-watchdog failure: ' . $e->getMessage());
    svaw_reply(500, ['ok' => false, 'agent' => 'autonomous_watchdog', 'error' => 'execution_failed']);
}
