<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap-env.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function svrw_reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function svrw_header(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$key] ?? ''));
}

function svrw_expected_key(): string
{
    foreach (['SHOPVIVALIZ_AGENT_KEY', 'AGENT_KEY', 'AUTONOMOUS_AGENT_KEY'] as $name) {
        $value = getenv($name);
        if (is_string($value) && trim($value) !== '') return trim($value);
    }
    return '';
}

function svrw_contains_forbidden_status(mixed $value): bool
{
    if (is_string($value)) {
        return in_array(strtolower(trim($value)), ['queued', 'degraded', 'simulated', 'mock', 'dry_run'], true);
    }
    if (!is_array($value)) return false;
    foreach ($value as $child) {
        if (svrw_contains_forbidden_status($child)) return true;
    }
    return false;
}

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $expected = svrw_expected_key();
    $provided = svrw_header('X-ShopVivaliz-Agent-Key');
    if ($expected === '') svrw_reply(503, ['ok' => false, 'error' => 'agent_key_not_configured']);
    if ($provided === '' || !hash_equals($expected, $provided)) svrw_reply(401, ['ok' => false, 'error' => 'unauthorized']);
}

require_once __DIR__ . '/../../includes/pdo-database.php';
require_once __DIR__ . '/../../agents/v9.2.84/app/RealWorkOrchestratorAgent.php';

$body = file_get_contents('php://input');
$input = json_decode(is_string($body) ? $body : '', true);
if (!is_array($input)) $input = [];
$options = [
    'blog_queue_depth' => max(3, min(36, (int)($input['blog_queue_depth'] ?? 12))),
];

try {
    $result = (new ShopvivalizRealWorkOrchestratorAgent())->run($options);
    $accepted = ($result['ok'] ?? false) === true
        && ($result['execution_status'] ?? '') === 'completed'
        && ($result['execution_completed'] ?? false) === true
        && (int)($result['work_evidence_count'] ?? 0) >= 5
        && !svrw_contains_forbidden_status($result);
    $result['execution_accepted'] = $accepted;
    svrw_reply($accepted ? 200 : 500, $result);
} catch (Throwable $e) {
    error_log('real-work-orchestrator failure: ' . $e->getMessage());
    svrw_reply(500, ['ok' => false, 'agent' => 'real_work_orchestrator', 'execution_accepted' => false, 'error' => 'execution_failed']);
}
