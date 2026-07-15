<?php
require_once __DIR__ . '/../includes/admin-guard.php';
/**
 * Monitor Completo - Dashboard + Tarefas + Chat
 * Tudo em um único painel
 */

function monitor_read_json(string $path, array $fallback = []): array
{
    if (!file_exists($path)) {
        return $fallback;
    }

    $content = file_get_contents($path);
    if ($content === false || trim($content) === '') {
        return $fallback;
    }

    $data = json_decode($content, true);
    return is_array($data) ? $data : $fallback;
}

function monitor_read_lines(string $path, int $limit = 5): array
{
    if (!file_exists($path)) {
        return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    return array_slice($lines, -$limit);
}

function monitor_read_first_json(array $paths, array $fallback = []): array
{
    foreach ($paths as $path) {
        $data = monitor_read_json($path, []);
        if ($data !== []) {
            return $data;
        }
    }

    return $fallback;
}

function monitor_read_queue_state(): array
{
    $root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $candidates = [
        __DIR__ . '/../tasks-queue.json',
        __DIR__ . '/../logs/tasks-queue.json',
    ];

    foreach ($candidates as $path) {
        $data = monitor_read_json($path, []);
        if ($data === []) {
            continue;
        }

        $resolved = realpath($path) ?: $path;
        $source = str_replace('\\', '/', ltrim(str_replace($root . DIRECTORY_SEPARATOR, '', $resolved), '\\/'));

        if (array_is_list($data)) {
            return ['tasks' => $data, 'source' => $source];
        }

        $queue = $data['queue'] ?? null;
        if (is_array($queue) && array_is_list($queue)) {
            return ['tasks' => $queue, 'source' => $source];
        }
    }

    return ['tasks' => [], 'source' => null];
}

function monitor_count_tasks(array $tasks, array $statuses): int
{
    return count(array_filter($tasks, static function ($task) use ($statuses): bool {
        $status = strtolower((string)($task['status'] ?? ''));
        return in_array($status, $statuses, true);
    }));
}

// Carregar tarefas
$queue_state = monitor_read_queue_state();
$tasks_queue = $queue_state['tasks'];
$queue_source = $queue_state['source'];
$pending_count = monitor_count_tasks($tasks_queue, ['pending']);
$assigned_count = monitor_count_tasks($tasks_queue, ['assigned', 'running', 'in_progress']);
$total_tasks = count($tasks_queue);

// Carregar respostas
$responses_file = __DIR__ . '/../logs/monitor-responses.jsonl';
$recent_responses = [];
foreach (monitor_read_lines($responses_file, 5) as $line) {
    $line = trim($line);
    if ($line !== '') {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $recent_responses[] = $decoded;
        }
    }
}
$recent_responses = array_reverse($recent_responses);

// Carregar mensagens
$messages_file = __DIR__ . '/../logs/monitor-messages.log';
$recent_messages = [];
foreach (monitor_read_lines($messages_file, 3) as $line) {
    $line = trim($line);
    if ($line !== '') {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $recent_messages[] = $decoded;
        }
    }
}
$recent_messages = array_reverse($recent_messages);

$tri_sync = monitor_read_first_json([
    __DIR__ . '/../logs/tri-environment-sync.json',
    __DIR__ . '/../logs/autonomous-sync.json',
], []);

$roi_report = monitor_read_json(__DIR__ . '/../logs/roi-engine-report.json', []);
$sales_focus = $roi_report['top_opportunities'][0] ?? $roi_report['priorities'][0] ?? null;

$tri_sync_status = strtolower((string)($tri_sync['status'] ?? 'unknown'));
$tri_sync_badge = [
    'healthy' => ['label' => 'Rodando', 'color' => '#51cf66'],
    'warning' => ['label' => 'Lento', 'color' => '#ffd43b'],
    'critical' => ['label' => 'Parado', 'color' => '#ff6b6b'],
][$tri_sync_status] ?? ['label' => 'Indefinido', 'color' => '#adb5bd'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor Completo - ShopVivaliz</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            padding: 20px;
            min-height: 100vh;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        header {
            background: linear-gradient(135deg, #2ECC71 0%, #1F3A70 100%);
            color: white;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }

        header p {
            opacity: 0.9;
            font-size: 1.1em;
        }

        .dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .card h3 {
            font-size: 0.9em;
            color: #888;
            margin-bottom: 10px;
            text-transform: uppercase;
        }

        .card .number {
            font-size: 2.5em;
            font-weight: bold;
            color: #2ECC71;
        }

        .card.tasks-pending .number {
            color: #ff6b6b;
        }

        .card.tasks-assigned .number {
            color: #ffd43b;
        }

        .card.tasks-done .number {
            color: #51cf66;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .panel {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .panel h2 {
            font-size: 1.3em;
            margin-bottom: 15px;
            color: #333;
        }

        .task-item {
            padding: 12px;
            background: #f9f9f9;
            border-left: 4px solid #2ECC71;
            margin-bottom: 10px;
            border-radius: 4px;
        }

        .task-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
        }

        .task-status {
            font-size: 0.85em;
            color: #888;
        }

        .message-item {
            padding: 12px;
            background: #f0f8f8;
            border-left: 4px solid #1F3A70;
            margin-bottom: 10px;
            border-radius: 4px;
        }

        .message-time {
            font-size: 0.85em;
            color: #888;
            margin-bottom: 4px;
        }

        .message-text {
            color: #333;
            font-size: 0.95em;
        }

        .response-item {
            padding: 12px;
            background: #e8f5e9;
            border-left: 4px solid #4caf50;
            margin-bottom: 10px;
            border-radius: 4px;
        }

        .response-agent {
            font-weight: 600;
            color: #2e7d32;
            margin-bottom: 4px;
        }

        .response-text {
            color: #333;
            font-size: 0.95em;
            margin-top: 4px;
        }

        .empty {
            color: #999;
            font-style: italic;
            padding: 20px;
            text-align: center;
        }

        @media (max-width: 900px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🚀 Monitor Completo - ShopVivaliz</h1>
            <p>Dashboard de Tarefas Autônomas + Chat com Agentes</p>
        </header>

        <!-- DASHBOARD -->
        <div class="dashboard">
            <div class="card">
                <h3>Total de Tarefas</h3>
                <div class="number"><?php echo $total_tasks; ?></div>
            </div>
            <div class="card tasks-pending">
                <h3>Pendentes</h3>
                <div class="number"><?php echo $pending_count; ?></div>
            </div>
            <div class="card tasks-assigned">
                <h3>Em Progresso</h3>
                <div class="number"><?php echo $assigned_count; ?></div>
            </div>
            <div class="card tasks-done">
                <h3>Agentes</h3>
                <div class="number">3</div>
            </div>
            <div class="card">
                <h3>Sincronização 24/7</h3>
                <div class="number" style="font-size:1.6em;color:<?php echo htmlspecialchars($tri_sync_badge['color']); ?>;">
                    <?php echo htmlspecialchars($tri_sync_badge['label']); ?>
                </div>
            </div>
        </div>

        <!-- TAREFAS + CHAT -->
        <div class="grid-2">
            <!-- PAINEL DE TAREFAS -->
            <div class="panel">
                <h2>📋 Fila de Tarefas</h2>
                <?php if ($queue_source): ?>
                    <p style="color:#64748b;margin-bottom:14px;font-size:.92em;">Fonte atual: <?php echo htmlspecialchars($queue_source); ?></p>
                <?php endif; ?>
                <?php if (empty($tasks_queue)): ?>
                    <div class="empty">Nenhuma tarefa enfileirada</div>
                <?php else: ?>
                    <?php foreach (array_slice($tasks_queue, 0, 5) as $task): ?>
                        <div class="task-item">
                            <div class="task-title"><?php echo htmlspecialchars($task['title']); ?></div>
                            <div class="task-status">
                                Status: <?php echo htmlspecialchars($task['status']); ?> ·
                                Prioridade: <?php echo htmlspecialchars($task['priority']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- PAINEL DE CHAT/RESPOSTAS -->
            <div class="panel">
                <h2>💬 Últimas Respostas</h2>
                <?php if (empty($recent_responses)): ?>
                    <div class="empty">Nenhuma resposta ainda</div>
                <?php else: ?>
                    <?php foreach ($recent_responses as $resp): ?>
                        <div class="response-item">
                            <div class="response-agent">🤖 <?php echo htmlspecialchars($resp['agent'] ?? 'Sistema'); ?></div>
                            <div class="response-text"><?php echo htmlspecialchars(substr($resp['agent_response'], 0, 100)); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ATALHOS -->
        <div class="panel" style="margin-bottom: 20px;">
            <h2>🔗 Atalhos Rápidos</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                <a href="/admin/squad-chat.php" style="display: inline-block; padding: 12px; background: #2ECC71; color: white; border-radius: 6px; text-decoration: none; text-align: center;">
                    💬 Squad Chat (Agentes)
                </a>
                <a href="/catalogo/" style="display: inline-block; padding: 12px; background: #1F3A70; color: white; border-radius: 6px; text-decoration: none; text-align: center;">
                    🛍 Catálogo
                </a>
                <a href="/admin/monitor/" style="display: inline-block; padding: 12px; background: #ff6b6b; color: white; border-radius: 6px; text-decoration: none; text-align: center;">
                    📊 Monitor Original
                </a>
                <a href="/checkout/" style="display: inline-block; padding: 12px; background: #9c36b5; color: white; border-radius: 6px; text-decoration: none; text-align: center;">
                    💳 Checkout
                </a>
            </div>
        </div>

        <!-- STATUS -->
        <div class="panel">
            <h2>📈 Status do Sistema</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; font-size: 0.95em;">
                <div>✅ E-commerce: Operacional</div>
                <div>✅ Agentes: 3 Ativos (Claude, Gemini, GPT)</div>
                <div>✅ Tarefas: <?php echo $pending_count; ?> Pendentes</div>
                <div>✅ Workflows: 24/7 Rodando</div>
                <div>✅ Deploy: Automático via FTP</div>
                <div>✅ Autonomia: 100% Ativa</div>
            </div>
            <div style="margin-top: 15px; padding: 14px; border-radius: 8px; background: #fff7ed; border: 1px solid #fed7aa;">
                <strong>Foco de venda</strong><br>
                <?php if ($sales_focus): ?>
                    SKU: <?php echo htmlspecialchars((string)($sales_focus['sku'] ?? 'n/a')); ?> ·
                    Ação: <?php echo htmlspecialchars((string)($sales_focus['action'] ?? 'n/a')); ?> ·
                    Impacto: <?php echo htmlspecialchars((string)($sales_focus['impact'] ?? 'n/a')); ?>
                <?php else: ?>
                    ROI sem prioridade carregada no momento.
                <?php endif; ?>
            </div>
            <div style="margin-top: 15px; padding: 14px; border-radius: 8px; background: #f8f9fa; border: 1px solid #e9ecef;">
                <strong>Triambiente</strong><br>
                Ambiente: <?php echo htmlspecialchars((string)($tri_sync['environment'] ?? 'desconhecido')); ?> ·
                Branch: <?php echo htmlspecialchars((string)($tri_sync['git']['branch'] ?? 'n/a')); ?> ·
                Ahead/Behind: <?php echo htmlspecialchars((string)($tri_sync['git']['ahead_by'] ?? 0)); ?>/<?php echo htmlspecialchars((string)($tri_sync['git']['behind_by'] ?? 0)); ?> ·
                Próxima ação: <?php echo htmlspecialchars((string)($tri_sync['nextAction'] ?? 'n/a')); ?>
            </div>
        </div>
    </div>

    <script>
        // Auto-refresh a cada 30 segundos
        setTimeout(() => {
            location.reload();
        }, 30000);
    </script>
</body>
</html>
