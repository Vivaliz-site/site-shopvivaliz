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

function svrw_component_errors(string $component, array $result): array
{
    $errors = [];
    foreach ((array)($result['errors'] ?? []) as $error) {
        $errors[] = ['component' => $component, 'detail' => $error];
    }
    if (($result['ok'] ?? false) !== true && $errors === []) {
        $errors[] = ['component' => $component, 'detail' => ['error' => 'component_failed_without_detail']];
    }
    return $errors;
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
require_once __DIR__ . '/../../agents/v9.2.84/app/CatalogMetadataAgent.php';
require_once __DIR__ . '/../../agents/v9.2.84/app/ConversionFunnelAgent.php';

$body = file_get_contents('php://input');
$input = json_decode(is_string($body) ? $body : '', true);
if (!is_array($input)) $input = [];
$options = [
    'blog_queue_depth' => max(3, min(36, (int)($input['blog_queue_depth'] ?? 12))),
    'catalog_metadata_limit' => max(1, min(500, (int)($input['catalog_metadata_limit'] ?? 100))),
];

try {
    $components = [
        'operational_work' => (new ShopvivalizRealWorkOrchestratorAgent())->run($options),
        'catalog_metadata' => (new ShopvivalizCatalogMetadataAgent())->run($options),
        'conversion_funnel' => (new ShopvivalizConversionFunnelAgent())->run($options),
    ];

    $actions = [];
    $errors = [];
    $changedItems = 0;
    foreach ($components as $name => $component) {
        $actions = array_merge($actions, (array)($component['actions'] ?? []));
        $errors = array_merge($errors, svrw_component_errors($name, $component));
        $changedItems += (int)($component['changed_items'] ?? 0);
    }

    $allComponentsOk = count(array_filter(
        $components,
        static fn(array $component): bool => ($component['ok'] ?? false) === true
            && ($component['execution_completed'] ?? false) === true
            && ($component['execution_status'] ?? '') === 'completed'
    )) === count($components);

    $result = [
        'ok' => $allComponentsOk && $errors === [],
        'agent' => 'real_work_orchestrator',
        'version' => '10.1.0-catalog-conversion',
        'execution_status' => $allComponentsOk && $errors === [] ? 'completed' : 'failed',
        'execution_completed' => $allComponentsOk && $errors === [],
        'started_at' => min(array_map(static fn(array $component): string => (string)($component['started_at'] ?? date('c')), $components)),
        'finished_at' => date('c'),
        'work_evidence_count' => count($actions),
        'changed_items' => $changedItems,
        'actions' => $actions,
        'components' => $components,
        'errors' => $errors,
    ];

    $accepted = $result['ok'] === true
        && $result['execution_status'] === 'completed'
        && $result['execution_completed'] === true
        && $result['work_evidence_count'] >= 9
        && ($components['catalog_metadata']['ok'] ?? false) === true
        && ($components['conversion_funnel']['ok'] ?? false) === true
        && !svrw_contains_forbidden_status($result);
    $result['execution_accepted'] = $accepted;
    svrw_reply($accepted ? 200 : 500, $result);
} catch (Throwable $e) {
    error_log('real-work-orchestrator failure: ' . $e->getMessage());
    svrw_reply(500, ['ok' => false, 'agent' => 'real_work_orchestrator', 'execution_accepted' => false, 'error' => 'execution_failed']);
}
