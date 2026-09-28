<?php

declare(strict_types=1);

function cbAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function cbSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

$runtimeDir = sys_get_temp_dir() . '/chatgpt-continuity-bridge-test-' . bin2hex(random_bytes(6));
mkdir($runtimeDir, 0700, true);

$token = 'test-bridge-token-' . bin2hex(random_bytes(8));
$port = 8000 + random_int(1000, 8999);
$root = dirname(__DIR__);

$env = array_merge($_ENV, [
    'CHATGPT_CONTINUITY_BRIDGE_TOKEN' => $token,
    'SHOPVIVALIZ_RUNTIME_DIR' => $runtimeDir,
]);

$proc = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $root],
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
    $pipes,
    $root,
    $env
);
cbAssert($proc !== false, 'php -S must start.');

// Give the built-in server a moment to bind.
$base = "http://127.0.0.1:{$port}/api/chatgpt-continuity/bridge.php";
$deadline = microtime(true) + 5.0;
$up = false;
while (microtime(true) < $deadline) {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => '{"operation":"heartbeat"}', 'ignore_errors' => true, 'timeout' => 1]]);
    $resp = @file_get_contents($base, false, $ctx);
    if ($resp !== false) { $up = true; break; }
    usleep(100000);
}
cbAssert($up, 'Bridge server must come up within 5s.');

function cbCall(string $url, string $token, array $body, bool $skipAuth = false): array {
    $headers = "Content-Type: application/json\r\n";
    if (!$skipAuth) $headers .= "Authorization: Bearer {$token}\r\n";
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => $headers,
        'content' => json_encode($body),
        'ignore_errors' => true,
        'timeout' => 5,
    ]]);
    $raw = file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) { $status = (int)$m[1]; }
    }
    return ['status' => $status, 'body' => json_decode((string)$raw, true)];
}

try {
    $unauth = cbCall($base, 'wrong-token', ['operation' => 'enqueue', 'task_id' => 'task-1', 'repository' => 'Vivaliz-site/site-shopvivaliz']);
    cbSame(401, $unauth['status'], 'Wrong token must be rejected with 401.');
    cbSame('UNAUTHORIZED', $unauth['body']['status'], 'Wrong token body must say UNAUTHORIZED.');

    $noAuth = cbCall($base, '', ['operation' => 'enqueue', 'task_id' => 'task-1', 'repository' => 'Vivaliz-site/site-shopvivaliz'], skipAuth: true);
    cbSame(401, $noAuth['status'], 'Missing Authorization header must be rejected with 401.');

    $enqueue = cbCall($base, $token, ['operation' => 'enqueue', 'task_id' => 'task-1', 'repository' => 'Vivaliz-site/site-shopvivaliz']);
    cbSame(200, $enqueue['status'], 'Valid enqueue must return 200.');
    cbSame(true, $enqueue['body']['enqueued'], 'First enqueue must report enqueued=true.');

    $dupe = cbCall($base, $token, ['operation' => 'enqueue', 'task_id' => 'task-1', 'repository' => 'Vivaliz-site/site-shopvivaliz']);
    cbSame(false, $dupe['body']['enqueued'], 'Duplicate enqueue while pending must report enqueued=false.');

    $badRepo = cbCall($base, $token, ['operation' => 'enqueue', 'task_id' => 'task-2', 'repository' => 'not-a-valid-repo-format']);
    cbSame(400, $badRepo['status'], 'Malformed repository must be rejected with 400.');

    $pull = cbCall($base, $token, ['operation' => 'pull']);
    cbSame(200, $pull['status'], 'Pull must return 200.');
    cbSame('JOB', $pull['body']['status'], 'Pull must find the pending job.');
    cbSame('task-1', $pull['body']['nudge']['task_id'], 'Pulled nudge must be task-1.');
    cbSame('CLAIMED', $pull['body']['nudge']['status'], 'Pull must claim the job.');

    $pullAgain = cbCall($base, $token, ['operation' => 'pull']);
    cbSame('NO_JOB', $pullAgain['body']['status'], 'A second immediate pull must find no other job.');

    $unconfirmed = cbCall($base, $token, ['operation' => 'result', 'task_id' => 'task-1', 'result_status' => 'SENT_UNCONFIRMED', 'detail' => 'typed continue; no assistant progress']);
    cbSame(200, $unconfirmed['status'], 'Retryable unconfirmed send must be accepted.');
    cbSame('ACK', $unconfirmed['body']['status'], 'Unconfirmed result must ACK.');

    $result = cbCall($base, $token, ['operation' => 'result', 'task_id' => 'task-1', 'result_status' => 'PROGRESS_CONFIRMED', 'detail' => 'assistant output advanced']);
    cbSame(200, $result['status'], 'Confirmed progress result must be accepted.');
    cbSame('ACK', $result['body']['status'], 'Confirmed result must ACK.');

    $unknownResult = cbCall($base, $token, ['operation' => 'result', 'task_id' => 'never-enqueued', 'result_status' => 'SENT']);
    cbSame(404, $unknownResult['status'], 'Result for an unknown task_id must 404.');

    $statusCall = cbCall($base, $token, ['operation' => 'status', 'task_id' => 'task-1']);
    cbSame('PROGRESS_CONFIRMED', $statusCall['body']['nudge']['status'], 'Status must reflect the latest confirmed progress result.');

    $badOp = cbCall($base, $token, ['operation' => 'not-a-real-operation']);
    cbSame(400, $badOp['status'], 'Unsupported operation must be rejected with 400.');

    $wrongMethod = @file_get_contents($base, false, stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer {$token}\r\n",
        'ignore_errors' => true,
    ]]));
    $methodStatus = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) { $methodStatus = (int)$m[1]; }
    }
    cbSame(405, $methodStatus, 'An authenticated GET must still be rejected with 405 Method Not Allowed (auth is checked before method, matching the Amazon Returns bridge ordering).');

    echo "CHATGPT_CONTINUITY_BRIDGE_TEST=PASS\n";
} finally {
    proc_terminate($proc);
    proc_close($proc);
    array_map('unlink', glob($runtimeDir . '/storage/private/chatgpt-continuity/*') ?: []);
    @rmdir($runtimeDir . '/storage/private/chatgpt-continuity');
    @rmdir($runtimeDir . '/storage/private');
    @rmdir($runtimeDir . '/storage');
    @rmdir($runtimeDir);
}
