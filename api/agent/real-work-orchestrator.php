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
        return in_array(strtolower(trim($value)), ['queued', 'degraded', 'simulated', 'mock', 'dry_run', 'dry-run'], true);
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
    if (($result['execution_completed'] ?? false) !== true) {
        $errors[] = ['component' => $component, 'detail' => ['error' => 'execution_not_completed']];
    }
    if (($result['execution_status'] ?? '') !== 'completed') {
        $errors[] = ['component' => $component, 'detail' => ['error' => 'execution_status_not_completed']];
    }
    if (!is_array($result['actions'] ?? null) || count($result['actions']) < 1) {
        $errors[] = ['component' => $component, 'detail' => ['error' => 'work_evidence_missing']];
    }
    if (svrw_contains_forbidden_status($result)) {
        $errors[] = ['component' => $component, 'detail' => ['error' => 'forbidden_execution_status']];
    }
    return $errors;
}

function svrw_persist_cycle(string $root, array $result): void
{
    $directory = $root . '/storage/agent-evidence';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('agent_cycle_evidence_directory_unavailable');
    }
    $path = $directory . '/latest-agent-cycle.json';
    $temporary = $path . '.tmp-' . getmypid();
    $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('agent_cycle_evidence_write_failed');
    }
    $readback = json_decode((string)file_get_contents($path), true);
    if (!is_array($readback) || ($readback['cycle_id'] ?? '') !== ($result['cycle_id'] ?? '')) {
        throw new RuntimeException('agent_cycle_evidence_readback_failed');
    }
}

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $expected = svrw_expected_key();
    $provided = svrw_header('X-ShopVivaliz-Agent-Key');
    if ($expected === '') svrw_reply(503, ['ok' => false, 'error' => 'agent_key_not_configured']);
    if ($provided === '' || !hash_equals($expected, $provided)) svrw_reply(401, ['ok' => false, 'error' => 'unauthorized']);
}

$root = dirname(__DIR__, 2);
require_once $root . '/includes/pdo-database.php';
require_once $root . '/includes/production-agent-registry.php';

$registry = svpa_registry();
$definitions = (array)($registry['agents'] ?? []);
if ($definitions === []) {
    svrw_reply(500, ['ok' => false, 'error' => 'production_agent_registry_empty']);
}

foreach ($definitions as $id => $definition) {
    $file = $root . '/' . ltrim((string)($definition['file'] ?? ''), '/');
    if (!is_file($file)) {
        svrw_reply(500, ['ok' => false, 'error' => 'registered_agent_file_missing', 'agent' => $id]);
    }
    require_once $file;
    $class = (string)($definition['class'] ?? '');
    if ($class === '' || !class_exists($class)) {
        svrw_reply(500, ['ok' => false, 'error' => 'registered_agent_class_missing', 'agent' => $id]);
    }
}

$body = file_get_contents('php://input');
$input = json_decode(is_string($body) ? $body : '', true);
if (!is_array($input)) $input = [];
$options = [
    'blog_queue_depth' => max(3, min(36, (int)($input['blog_queue_depth'] ?? 12))),
    'catalog_metadata_limit' => max(1, min(500, (int)($input['catalog_metadata_limit'] ?? 100))),
    'media_mismatch_limit' => max(10, min(500, (int)($input['media_mismatch_limit'] ?? 200))),
];

$cycleId = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(6));
$startedAt = gmdate('c');

try {
    $components = [];
    foreach ($definitions as $id => $definition) {
        $class = (string)$definition['class'];
        $component = (new $class())->run($options);
        if (!is_array($component)) {
            $component = [
                'ok' => false,
                'execution_status' => 'failed',
                'execution_completed' => false,
                'actions' => [],
                'errors' => [['error' => 'agent_returned_non_array']],
            ];
        }
        $component['registry_id'] = $id;
        $components[$id] = $component;
    }

    $actions = [];
    $errors = [];
    $changedItems = 0;
    foreach ($components as $name => $component) {
        foreach ((array)($component['actions'] ?? []) as $action) {
            if (is_array($action)) {
                $action['agent'] = $name;
                $actions[] = $action;
            }
        }
        $errors = array_merge($errors, svrw_component_errors($name, $component));
        $changedItems += (int)($component['changed_items'] ?? 0);
    }

    $allComponentsOk = count(array_filter(
        $components,
        static fn(array $component): bool => ($component['ok'] ?? false) === true
            && ($component['execution_completed'] ?? false) === true
            && ($component['execution_status'] ?? '') === 'completed'
            && is_array($component['actions'] ?? null)
            && count($component['actions']) >= 1
    )) === count($definitions);

    $minimumEvidence = 12;
    $accepted = $allComponentsOk
        && $errors === []
        && count($actions) >= $minimumEvidence
        && !svrw_contains_forbidden_status($components);

    $result = [
        'ok' => $accepted,
        'agent' => 'production_agent_registry',
        'version' => '11.0.0-all-real-agents',
        'cycle_id' => $cycleId,
        'registry_sha256' => svpa_registry_hash($registry),
        'schedule_minutes' => (int)($registry['schedule_minutes'] ?? 15),
        'active_agent_count' => count($definitions),
        'active_agents' => array_keys($definitions),
        'execution_status' => $accepted ? 'completed' : 'failed',
        'execution_completed' => $accepted,
        'execution_accepted' => $accepted,
        'started_at' => $startedAt,
        'finished_at' => gmdate('c'),
        'minimum_work_evidence_count' => $minimumEvidence,
        'work_evidence_count' => count($actions),
        'changed_items' => $changedItems,
        'actions' => $actions,
        'components' => $components,
        'errors' => $errors,
        'evidence_file' => 'storage/agent-evidence/latest-agent-cycle.json',
    ];

    try {
        svrw_persist_cycle($root, $result);
    } catch (Throwable $error) {
        $result['ok'] = false;
        $result['execution_status'] = 'failed';
        $result['execution_completed'] = false;
        $result['execution_accepted'] = false;
        $result['errors'][] = ['component' => 'registry', 'detail' => ['error' => $error->getMessage()]];
    }

    svrw_reply($result['execution_accepted'] === true ? 200 : 500, $result);
} catch (Throwable $error) {
    error_log('production agent registry failure: ' . $error->getMessage());
    svrw_reply(500, [
        'ok' => false,
        'agent' => 'production_agent_registry',
        'cycle_id' => $cycleId,
        'execution_status' => 'failed',
        'execution_completed' => false,
        'execution_accepted' => false,
        'error' => 'execution_failed',
    ]);
}
