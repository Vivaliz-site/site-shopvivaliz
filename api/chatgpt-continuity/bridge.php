<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap-env.php';
require_once dirname(__DIR__, 2) . '/includes/remote-bridge-auth.php';
require_once dirname(__DIR__, 2) . '/includes/chatgpt-continuity/PendingNudgeStore.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function sv_cgn_bridge_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sv_cgn_bridge_auth_header(): string
{
    $apacheHeaders = function_exists('apache_request_headers') ? apache_request_headers() : [];
    return SvRemoteBridgeAuth::resolveAuthorizationHeader($_SERVER, is_array($apacheHeaders) ? $apacheHeaders : []);
}

function sv_cgn_bridge_store(): SvChatgptContinuityPendingNudgeStore
{
    $runtimeBase = rtrim((string)(getenv('SHOPVIVALIZ_RUNTIME_DIR') ?: ''), '/\\');
    $dir = ($runtimeBase !== '' ? $runtimeBase : dirname(__DIR__, 2)) . '/storage/private/chatgpt-continuity';
    return new SvChatgptContinuityPendingNudgeStore($dir . '/pending-nudges.json');
}

$expectedToken = getenv('CHATGPT_CONTINUITY_BRIDGE_TOKEN');
$expectedToken = is_string($expectedToken) ? trim($expectedToken) : '';
if (!SvRemoteBridgeAuth::authorized($expectedToken, sv_cgn_bridge_auth_header())) {
    header('WWW-Authenticate: Bearer realm="ShopVivaliz ChatGPT Continuity Bridge"');
    sv_cgn_bridge_reply(['status' => 'UNAUTHORIZED'], 401);
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    header('Allow: POST');
    sv_cgn_bridge_reply(['status' => 'METHOD_NOT_ALLOWED'], 405);
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
    sv_cgn_bridge_reply(['status' => 'PAYLOAD_TOO_LARGE'], 413);
}

$raw = (string)file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    sv_cgn_bridge_reply(['status' => 'INVALID_JSON'], 400);
}
$operation = strtolower(trim((string)($input['operation'] ?? '')));
if (!in_array($operation, ['heartbeat', 'enqueue', 'pull', 'result', 'status'], true)) {
    sv_cgn_bridge_reply(['status' => 'INVALID_OPERATION'], 400);
}

if ($operation === 'heartbeat') {
    sv_cgn_bridge_reply(['status' => 'OK', 'server_time' => gmdate(DATE_ATOM)]);
}

$store = sv_cgn_bridge_store();

function sv_cgn_safe_task_id(mixed $value): string
{
    $taskId = trim((string)$value);
    return preg_match('/^[A-Za-z0-9._-]{1,128}$/', $taskId) === 1 ? $taskId : '';
}

if ($operation === 'enqueue') {
    $taskId = sv_cgn_safe_task_id($input['task_id'] ?? '');
    $repository = trim((string)($input['repository'] ?? ''));
    if ($taskId === '' || $repository === '' || !preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $repository)) {
        sv_cgn_bridge_reply(['status' => 'INVALID_REQUEST'], 400);
    }
    try {
        $enqueued = $store->enqueue($taskId, $repository, gmdate(DATE_ATOM));
        sv_cgn_bridge_reply(['status' => 'OK', 'enqueued' => $enqueued]);
    } catch (Throwable $e) {
        error_log('[chatgpt-continuity-bridge-enqueue] ' . $e->getMessage());
        sv_cgn_bridge_reply(['status' => 'SERVER_ERROR'], 500);
    }
}

if ($operation === 'pull') {
    try {
        $nudge = $store->pullOldest();
        if ($nudge === null) {
            sv_cgn_bridge_reply(['status' => 'NO_JOB']);
        }
        sv_cgn_bridge_reply(['status' => 'JOB', 'nudge' => $nudge]);
    } catch (Throwable $e) {
        error_log('[chatgpt-continuity-bridge-pull] ' . $e->getMessage());
        sv_cgn_bridge_reply(['status' => 'SERVER_ERROR'], 500);
    }
}

if ($operation === 'result') {
    $taskId = sv_cgn_safe_task_id($input['task_id'] ?? '');
    $resultStatus = strtoupper(trim((string)($input['result_status'] ?? '')));
    $detail = isset($input['detail']) ? (string)$input['detail'] : null;
    $allowed = ['SENT', 'STALLED_NOT_CONFIRMED', 'CONVERSATION_NOT_FOUND', 'ERROR'];
    if ($taskId === '' || !in_array($resultStatus, $allowed, true)) {
        sv_cgn_bridge_reply(['status' => 'INVALID_REQUEST'], 400);
    }
    try {
        $recorded = $store->recordResult($taskId, $resultStatus, $detail);
        if (!$recorded) {
            sv_cgn_bridge_reply(['status' => 'JOB_NOT_FOUND'], 404);
        }
        sv_cgn_bridge_reply(['status' => 'ACK']);
    } catch (Throwable $e) {
        error_log('[chatgpt-continuity-bridge-result] ' . $e->getMessage());
        sv_cgn_bridge_reply(['status' => 'SERVER_ERROR'], 500);
    }
}

// $operation === 'status'
$taskId = sv_cgn_safe_task_id($input['task_id'] ?? '');
if ($taskId === '') {
    sv_cgn_bridge_reply(['status' => 'INVALID_REQUEST'], 400);
}
try {
    $row = $store->status($taskId);
    if ($row === null) {
        sv_cgn_bridge_reply(['status' => 'NOT_FOUND'], 404);
    }
    sv_cgn_bridge_reply(['status' => 'OK', 'nudge' => $row]);
} catch (Throwable $e) {
    error_log('[chatgpt-continuity-bridge-status] ' . $e->getMessage());
    sv_cgn_bridge_reply(['status' => 'SERVER_ERROR'], 500);
}
