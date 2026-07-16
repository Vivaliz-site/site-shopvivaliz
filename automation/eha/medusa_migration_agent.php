<?php
/**
 * Medusa Migration Agent
 *
 * Avalia o workspace Medusa, decide o proximo passo operacional e
 * publica esse estado em relatorios e na fila de tarefas do projeto.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$medusaRoot = $root . '/medusa';
$backendRoot = $medusaRoot . '/apps/backend';
$storefrontRoot = $medusaRoot . '/apps/storefront';
$reportsDir = __DIR__ . '/reports';
$queuePath = $root . '/logs/tasks-queue.json';
$applyChanges = getenv('MEDUSA_AGENT_APPLY') !== '0';

if (!is_dir($reportsDir)) {
    mkdir($reportsDir, 0755, true);
}

$appliedActions = [];

if ($applyChanges) {
    $appliedActions = apply_medusa_safe_steps($backendRoot, $storefrontRoot);
}

$state = [
    'timestamp' => date('c'),
    'workspace_exists' => is_dir($medusaRoot),
    'backend_exists' => is_dir($backendRoot),
    'storefront_exists' => is_dir($storefrontRoot),
    'root_package_exists' => is_file($medusaRoot . '/package.json'),
    'node_modules_exists' => is_dir($medusaRoot . '/node_modules/.pnpm'),
    'backend_env_template_exists' => is_file($backendRoot . '/.env.template'),
    'backend_env_exists' => is_file($backendRoot . '/.env'),
    'storefront_env_example_exists' => is_file($storefrontRoot . '/.env.local.example'),
    'storefront_env_exists' => is_file($storefrontRoot . '/.env.local'),
    'backend_config_exists' => is_file($backendRoot . '/medusa-config.ts'),
    'storefront_package_exists' => is_file($storefrontRoot . '/package.json'),
    'storefront_publishable_key_present' => storefront_publishable_key_present($storefrontRoot),
];

$decision = decide_medusa_next_step($state);
$report = [
    'agent' => 'medusa_migration_agent',
    'status' => $decision['status'],
    'next_step_key' => $decision['key'],
    'next_step_title' => $decision['title'],
    'next_step_description' => $decision['description'],
    'applied_actions' => $appliedActions,
    'state' => $state,
];

file_put_contents(
    $reportsDir . '/medusa-last-run.json',
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);

file_put_contents(
    $reportsDir . '/medusa-run-history.jsonl',
    json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

trim_history($reportsDir . '/medusa-run-history.jsonl', 120);
sync_medusa_task($queuePath, $decision);

echo 'MEDUSA_AGENT: ' . $decision['key'] . ' | status=' . $decision['status'] . PHP_EOL;
exit(0);

function decide_medusa_next_step(array $state): array
{
    if (!$state['workspace_exists'] || !$state['backend_exists'] || !$state['storefront_exists']) {
        return [
            'status' => 'blocked',
            'key' => 'bootstrap_medusa_workspace',
            'title' => 'Medusa: restaurar workspace base',
            'description' => 'Garantir que medusa/apps/backend e medusa/apps/storefront existam no repositorio.',
        ];
    }

    if (!$state['node_modules_exists']) {
        return [
            'status' => 'in_progress',
            'key' => 'install_medusa_dependencies',
            'title' => 'Medusa: instalar dependencias do workspace',
            'description' => 'Executar pnpm install em medusa/ para materializar backend e storefront.',
        ];
    }

    if (!$state['backend_env_exists']) {
        return [
            'status' => 'in_progress',
            'key' => 'configure_backend_env',
            'title' => 'Medusa: configurar ambiente do backend',
            'description' => 'Criar medusa/apps/backend/.env a partir do template com DATABASE_URL, JWT_SECRET, COOKIE_SECRET e CORS locais.',
        ];
    }

    if (!$state['storefront_env_exists']) {
        return [
            'status' => 'in_progress',
            'key' => 'configure_storefront_env',
            'title' => 'Medusa: configurar ambiente da storefront',
            'description' => 'Criar medusa/apps/storefront/.env.local apontando NEXT_PUBLIC_BASE_URL e URL da API Medusa.',
        ];
    }

    if (!$state['storefront_publishable_key_present']) {
        return [
            'status' => 'in_progress',
            'key' => 'generate_publishable_key',
            'title' => 'Medusa: gerar publishable key',
            'description' => 'Subir o backend e gerar uma publishable API key valida para a storefront consumir a Store API.',
        ];
    }

    return [
        'status' => 'ready_for_boot',
        'key' => 'boot_medusa_stack',
        'title' => 'Medusa: subir backend e storefront',
        'description' => 'Rodar pnpm backend:dev e pnpm storefront:dev, validar admin em :9000 e storefront em :8000.',
    ];
}

function sync_medusa_task(string $queuePath, array $decision): void
{
    $tasks = [];
    if (is_file($queuePath)) {
        $decoded = json_decode((string) file_get_contents($queuePath), true);
        if (is_array($decoded)) {
            $tasks = $decoded;
        }
    }

    $taskId = 'task-medusa-next-step';
    $updated = false;

    foreach ($tasks as &$task) {
        if (($task['id'] ?? '') !== $taskId) {
            continue;
        }

        $task['title'] = $decision['title'];
        $task['description'] = $decision['description'];
        $task['priority'] = 'high';
        $task['assigned_to'] = 'claude';
        $task['status'] = 'pending';
        $task['started_at'] = null;
        $task['completed_at'] = null;
        $task['updated_at'] = date('c');
        $updated = true;
        break;
    }
    unset($task);

    if (!$updated) {
        $tasks[] = [
            'id' => $taskId,
            'title' => $decision['title'],
            'description' => $decision['description'],
            'priority' => 'high',
            'assigned_to' => 'claude',
            'status' => 'pending',
            'created_at' => date('c'),
            'started_at' => null,
            'completed_at' => null,
            'updated_at' => date('c'),
        ];
    }

    file_put_contents(
        $queuePath,
        json_encode($tasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
    );
}

function trim_history(string $path, int $maxLines): void
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES) ?: [];
    if (count($lines) <= $maxLines) {
        return;
    }

    file_put_contents($path, implode(PHP_EOL, array_slice($lines, -$maxLines)) . PHP_EOL);
}

function apply_medusa_safe_steps(string $backendRoot, string $storefrontRoot): array
{
    $actions = [];

    $backendEnvPath = $backendRoot . '/.env';
    if (!is_file($backendEnvPath)) {
        $backendEnv = implode(PHP_EOL, [
            'STORE_CORS=http://localhost:8000,http://127.0.0.1:8000',
            'ADMIN_CORS=http://localhost:9000,http://127.0.0.1:9000,http://localhost:5173',
            'AUTH_CORS=http://localhost:9000,http://127.0.0.1:9000,http://localhost:8000',
            'REDIS_URL=redis://localhost:6379',
            'JWT_SECRET=' . bin2hex(random_bytes(32)),
            'COOKIE_SECRET=' . bin2hex(random_bytes(32)),
            'DATABASE_URL=postgres://localhost:5432/shopvivaliz_medusa',
            'DB_NAME=shopvivaliz_medusa',
            '',
        ]);
        file_put_contents($backendEnvPath, $backendEnv);
        $actions[] = 'created_backend_env';
    }

    $storefrontEnvPath = $storefrontRoot . '/.env.local';
    if (!is_file($storefrontEnvPath)) {
        $storefrontEnv = implode(PHP_EOL, [
            'NEXT_PUBLIC_BASE_URL=http://localhost:8000',
            'NEXT_PUBLIC_MEDUSA_BACKEND_URL=http://localhost:9000',
            'NEXT_PUBLIC_MEDUSA_PUBLISHABLE_KEY=pk_test_replace_me',
            'NEXT_PUBLIC_DEFAULT_REGION=br',
            '',
        ]);
        file_put_contents($storefrontEnvPath, $storefrontEnv);
        $actions[] = 'created_storefront_env';
    }

    return $actions;
}

function storefront_publishable_key_present(string $storefrontRoot): bool
{
    $envPath = $storefrontRoot . '/.env.local';
    if (!is_file($envPath)) {
        return false;
    }

    $contents = (string) file_get_contents($envPath);
    if (!preg_match('/^NEXT_PUBLIC_MEDUSA_PUBLISHABLE_KEY=(.+)$/m', $contents, $matches)) {
        return false;
    }

    $value = trim($matches[1]);
    if ($value === '' || $value === 'pk_test_replace_me') {
        return false;
    }

    return str_starts_with($value, 'pk_');
}
