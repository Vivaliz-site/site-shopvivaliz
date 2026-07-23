<?php
/**
 * REMOTE COMMAND EXECUTOR - iOS Integration
 * Recebe comandos via POST do iPhone/iPad e executa localmente
 *
 * Uso (via curl/Siri):
 *   curl -X POST https://shopvivaliz.com.br/api/remote-command.php \
 *     -H "X-Command-Token: seu-token-secreto" \
 *     -H "Content-Type: application/json" \
 *     -d '{"command":"git status","timeout":30}'
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');

// Token de segurança (alterar para algo mais forte)
define('COMMAND_TOKEN', getenv('REMOTE_COMMAND_TOKEN') ?: 'dev-token-change-me');

// Comandos permitidos (whitelist para segurança)
$ALLOWED_COMMANDS = [
    'git' => ['git status', 'git log', 'git diff', 'git add', 'git commit', 'git push', 'git pull', 'git reset'],
    'node' => ['npm run', 'npm install', 'node'],
    'system' => ['pwd', 'ls', 'dir', 'echo', 'date'],
];

function execute_command(string $cmd, int $timeout = 30): array {
    if (PHP_OS_FAMILY === 'Windows') {
        $process = proc_open("powershell.exe -NoProfile -Command \"$cmd\"", [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
    } else {
        $process = proc_open($cmd, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
    }

    if (!is_resource($process)) {
        return [
            'success' => false,
            'output' => 'Failed to start process',
            'error' => 'Process error',
        ];
    }

    $start = time();
    $output = '';
    $error = '';

    while (!feof($pipes[1]) && time() - $start < $timeout) {
        $output .= fgets($pipes[1], 1024);
        usleep(100000);
    }

    while (!feof($pipes[2])) {
        $error .= fgets($pipes[2], 1024);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return [
        'success' => true,
        'output' => trim($output),
        'error' => trim($error),
        'timestamp' => date('Y-m-d H:i:s'),
    ];
}

function is_command_allowed(string $cmd): bool {
    global $ALLOWED_COMMANDS;

    $cmd_lower = strtolower(trim($cmd));

    foreach ($ALLOWED_COMMANDS as $category => $commands) {
        foreach ($commands as $allowed) {
            if (strpos($cmd_lower, strtolower($allowed)) === 0) {
                return true;
            }
        }
    }

    return false;
}

// Verificar token
$token = $_SERVER['HTTP_X_COMMAND_TOKEN'] ?? '';
if ($token !== COMMAND_TOKEN) {
    http_response_code(401);
    die(json_encode([
        'success' => false,
        'error' => 'Unauthorized - Invalid token',
        'hint' => 'Set X-Command-Token header',
    ]));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode([
        'success' => false,
        'error' => 'Method not allowed - Use POST',
    ]));
}

$input = json_decode(file_get_contents('php://input'), true);
$command = trim($input['command'] ?? '');
$timeout = min((int)($input['timeout'] ?? 30), 300); // Max 5 min

if (empty($command)) {
    http_response_code(400);
    die(json_encode([
        'success' => false,
        'error' => 'Missing command parameter',
    ]));
}

if (!is_command_allowed($command)) {
    http_response_code(403);
    die(json_encode([
        'success' => false,
        'error' => 'Command not allowed',
        'command' => $command,
        'hint' => 'Allowed: git, npm, node, pwd, ls, dir, echo',
    ]));
}

// Executar comando
$result = execute_command($command, $timeout);

http_response_code($result['success'] ? 200 : 500);
echo json_encode($result);
