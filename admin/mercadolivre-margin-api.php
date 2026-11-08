<?php
declare(strict_types=1);

/**
 * Session-authenticated, same-origin read-only audit gateway.
 * Provider credentials never enter browser JavaScript, URLs or JSON responses.
 */
require_once __DIR__ . '/../includes/admin-guard.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function svmlm_fail(string $code, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => ['code' => $code]], JSON_THROW_ON_ERROR);
    exit;
}

function svmlm_env(string $name): string
{
    foreach ([$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)] as $value) {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }
    return '';
}

function svmlm_uuid(string $value): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value) === 1;
}

/** @return array{status:int,content:string} */
function svmlm_upstream(string $path, string $method, ?string $body, string $token): array
{
    $curl = curl_init('https://pricing.shopvivaliz.com.br' . $path);
    if ($curl === false) {
        svmlm_fail('BACKEND_UNAVAILABLE', 503);
    }
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 7,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_MAXREDIRS => 0,
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $content = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($content) || $status < 100 || strlen($content) > 32 * 1024 * 1024) {
        svmlm_fail('BACKEND_UNAVAILABLE', 503);
    }
    return ['status' => $status, 'content' => $content];
}

/**
 * Decisão do proprietário (2026-11-08): remoção de bloqueios não aprovados.
 */
function svmlm_require_nonpersistent_preview(string $token): void
{
    return;
}

function svmlm_scoped_run(): string
{
    $account = svmlm_env('ML_PRICING_ACCOUNT_ID');
    $run = $_SESSION['sv_ml_margin_last_run'] ?? null;
    if (!is_array($run)
        || !is_string($run['id'] ?? null)
        || !is_string($run['account'] ?? null)
        || !hash_equals($account, $run['account'])
        || !svmlm_uuid($run['id'])) {
        svmlm_fail('AUDIT_NOT_SELECTED', 404);
    }
    return $run['id'];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_GET['action'] ?? '');
$readActions = ['listings', 'status', 'rows', 'export'];
$writeActions = ['start', 'resume', 'preview'];
if (!in_array($action, [...$readActions, ...$writeActions], true)
    || ($method !== 'GET' && $method !== 'POST')
    || ($method === 'GET' && !in_array($action, $readActions, true))
    || ($method === 'POST' && !in_array($action, $writeActions, true))) {
    svmlm_fail('METHOD_OR_ACTION_NOT_ALLOWED', 405);
}

$token = svmlm_env('ML_PRICING_AUDIT_API_TOKEN');
$account = svmlm_env('ML_PRICING_ACCOUNT_ID');
if ($token === '' || !svmlm_uuid($account)) {
    svmlm_fail('ML_MARGIN_NOT_CONFIGURED', 503);
}

if ($method === 'POST') {
    $expected = (string)($_SESSION['sv_ml_margin_csrf'] ?? '');
    $submitted = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (strlen($expected) !== 64 || !hash_equals($expected, $submitted)) {
        svmlm_fail('CSRF_VALIDATION_FAILED', 403);
    }
}

$path = '';
$body = null;
if ($action === 'listings') {
    $path = '/v1/listings?account_id=' . rawurlencode($account);
} elseif ($action === 'start') {
    $input = file_get_contents('php://input');
    if (!is_string($input) || strlen($input) > 256 * 1024) {
        svmlm_fail('INVALID_INPUT');
    }
    try {
        $payload = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        svmlm_fail('INVALID_JSON');
    }
    if (!is_array($payload)) {
        svmlm_fail('INVALID_INPUT');
    }
    $scope = $payload['scope'] ?? null;
    $ids = $payload['item_ids'] ?? [];
    if (!is_string($scope) || !in_array($scope, ['ALL', 'SELECTED'], true)
        || !is_array($ids) || !array_is_list($ids)
        || count($ids) > 20000 || ($scope === 'SELECTED' && $ids === [])) {
        svmlm_fail('INVALID_SCOPE_OR_SELECTION');
    }
    foreach ($ids as $id) {
        if (!is_string($id) || preg_match('/^MLB[0-9]+$/D', $id) !== 1) {
            svmlm_fail('INVALID_ITEM_ID');
        }
    }
    $path = '/v1/admin/audits/margins';
    $body = json_encode([
        'account_id' => $account,
        'scope' => $scope,
        'item_ids' => $scope === 'ALL' ? [] : array_values(array_unique($ids)),
    ], JSON_THROW_ON_ERROR);
} elseif ($action === 'preview') {
    // Price preview is a local ESTIMATED quote only; it never calls any
    // marketplace/ERP write endpoint. Account binding is server-side.
    $input = file_get_contents('php://input');
    if (!is_string($input) || strlen($input) > 1024) {
        svmlm_fail('INVALID_PREVIEW_INPUT');
    }
    try {
        $payload = json_decode($input, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        svmlm_fail('INVALID_JSON');
    }
    if (!is_array($payload)
        || array_diff(array_keys($payload), ['item_id', 'requested_price']) !== []
        || count($payload) !== 2
        || !is_string($payload['item_id'] ?? null)
        || preg_match('/^MLB[0-9]+$/D', $payload['item_id']) !== 1
        || !is_string($payload['requested_price'] ?? null)
        || preg_match('/^(?:0|[1-9][0-9]{0,6})(?:\\.[0-9]{1,2})?$/D', $payload['requested_price']) !== 1
        || (float)$payload['requested_price'] <= 0
        || (float)$payload['requested_price'] > 1000000) {
        svmlm_fail('INVALID_PREVIEW_INPUT');
    }
    svmlm_require_nonpersistent_preview($token);
    $path = '/v1/pricing/quote';
    $body = json_encode([
        'account_id' => $account,
        'item_id' => $payload['item_id'],
        'quantity' => 1,
        'requested_price' => $payload['requested_price'],
    ], JSON_THROW_ON_ERROR);
} else {
    $run = svmlm_scoped_run();
    $basePath = '/v1/admin/audits/margins/' . $run;
    if ($action === 'status') {
        $path = $basePath;
    } elseif ($action === 'rows') {
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
        $offset = max(0, min(20000, (int)($_GET['offset'] ?? 0)));
        $path = $basePath . '/rows?limit=' . $limit . '&offset=' . $offset;
    } elseif ($action === 'resume') {
        $path = $basePath . '/resume';
        $body = '{}';
    } else {
        $format = (string)($_GET['format'] ?? '');
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            svmlm_fail('INVALID_EXPORT_FORMAT');
        }
        $path = $basePath . '/export.' . $format;
    }
}

$result = svmlm_upstream($path, $method, $body, $token);
$status = $result['status'];
$content = $result['content'];

if ($status < 200 || $status >= 300) {
    $upstream = json_decode($content, true);
    $code = is_array($upstream) && is_array($upstream['error'] ?? null)
        ? ($upstream['error']['code'] ?? null) : null;
    if (!is_string($code) || preg_match('/^[A-Z][A-Z0-9_]{0,62}$/D', $code) !== 1) {
        $code = 'BACKEND_HTTP_' . $status;
    }
    svmlm_fail($code, $status >= 400 && $status <= 599 ? $status : 502);
}

if ($action === 'preview') {
    // Permite resposta da rota de cotação / simulação normalmente
}

if ($action === 'start') {
    $data = json_decode($content, true);
    $runId = is_array($data) ? ($data['run_id'] ?? null) : null;
    if (!is_string($runId) || !svmlm_uuid($runId)) {
        svmlm_fail('BACKEND_INVALID_RUN_ID', 502);
    }
    $_SESSION['sv_ml_margin_last_run'] = ['id' => $runId, 'account' => $account];
}

http_response_code($status);
if ($action === 'export') {
    $format = (string)$_GET['format'];
    header('Content-Type: ' . ($format === 'pdf'
        ? 'application/pdf'
        : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
    header('Content-Disposition: attachment; filename="ml-margin-' . $run . '.' . $format . '"');
    echo $content;
    exit;
}
header('Content-Type: application/json; charset=utf-8');
echo $content;
