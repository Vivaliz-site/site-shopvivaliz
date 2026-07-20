<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Valida SQUAD_TOKEN para autenticação
$token = $_SERVER['HTTP_X_SQUAD_TOKEN'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? ($_GET['token'] ?? '');
$token = ltrim($token, 'Bearer ');

function svet_env(string $key): string {
    $v = getenv($key);
    if ($v !== false && $v !== '') return $v;
    $envFile = dirname(__DIR__, 2) . '/.env';
    if (!is_file($envFile)) return '';
    static $cache = [];
    if (!isset($cache[$key])) {
        $cache[$key] = '';
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $val] = explode('=', $line, 2);
            $k = trim($k); $val = trim(trim($val), '"\'');
            if ($k === $key) { $cache[$key] = $val; break; }
        }
    }
    return $cache[$key];
}

$expectedToken = svet_env('SQUAD_TOKEN');
$authenticated = $expectedToken !== '' && hash_equals($expectedToken, $token);

if (!$authenticated) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized. Envie X-Squad-Token.']);
    exit;
}

$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

$result = match($action) {
    'watchdog' => (function() {
        $url = 'https://shopvivaliz.com.br/api/agent/autonomous-watchdog.php';
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        return ['action' => 'watchdog', 'response' => json_decode($body ?: '{}', true)];
    })(),

    'report' => (function() {
        $url = 'https://shopvivaliz.com.br/api/agent/autonomous-report.php';
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        return ['action' => 'report', 'response' => json_decode($body ?: '{}', true)];
    })(),

    'status' => [
        'action'  => 'status',
        'site'    => 'https://shopvivaliz.com.br',
        'agents'  => [
            'watchdog'  => 'https://shopvivaliz.com.br/api/agent/autonomous-watchdog.php',
            'report'    => 'https://shopvivaliz.com.br/api/agent/autonomous-report.php',
            'squad'     => 'https://shopvivaliz.com.br/api/agent/squad-chat.php',
            'ml_token'  => 'https://shopvivaliz.com.br/api/ml/token.php',
        ],
        'available_actions' => ['watchdog', 'report', 'status'],
    ],

    default => ['action' => 'none', 'available' => ['watchdog', 'report', 'status']],
};

echo json_encode(array_merge(['ok' => true, 'generated_at' => date('c')], $result),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
