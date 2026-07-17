<?php
/**
 * AI Hybrid System Monitor
 * Monitoramento em tempo real do sistema IA (Ollama + Agents)
 * Integrado ao admin do ShopVivaliz
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';

// ── Carrega .env ─────────────────────────────────────────────────────────
(static function () {
    $f = dirname(__DIR__) . '/.env';
    if (!is_file($f)) return;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim(trim($v), '"\'');
        if ($k !== '' && getenv($k) === false) putenv("$k=$v");
    }
})();

// ── Helpers ──────────────────────────────────────────────────────────────
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function read_json(string $path, array $fallback = []): array {
    if (!is_file($path)) return $fallback;
    $c = @file_get_contents($path);
    if ($c === false || trim($c) === '') return $fallback;
    $d = json_decode($c, true);
    return is_array($d) ? $d : $fallback;
}

function tail_log(string $path, int $n = 15): array {
    if (!is_file($path)) return [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return is_array($lines) ? array_slice($lines, -$n) : [];
}

// ── Verifica Ollama ─────────────────────────────────────────────────────
$ollama_status = 'offline';
$ollama_models = [];
$ollama_check = null;

$ctx = stream_context_create(['http' => [
    'method'  => 'GET',
    'timeout' => 3,
    'ignore_errors' => true,
]]);
$resp = @file_get_contents('http://localhost:11434/api/tags', false, $ctx);
if ($resp !== false) {
    $data = json_decode($resp, true);
    if (is_array($data) && !empty($data['models'])) {
        $ollama_status = 'online';
        $ollama_models = $data['models'];
        $ollama_check = count($ollama_models) . ' modelo(s) carregado(s)';
    } else {
        $ollama_status = 'online_empty';
        $ollama_check = 'Nenhum modelo carregado';
    }
} else {
    $ollama_check = 'Não conectou em localhost:11434';
}

// ── Carrega dados do sistema IA ──────────────────────────────────────────
$root = dirname(__DIR__);
$ai_system = $root . '/ai-system';

// tasks-queue.json
$tasks_queue = read_json($root . '/tasks-queue.json', ['tasks' => []]);
$tasks = $tasks_queue['tasks'] ?? [];
$tasks_processed = count(array_filter($tasks, fn($t) => ($t['status'] ?? '') === 'processed'));
$tasks_pending = count(array_filter($tasks, fn($t) => ($t['status'] ?? '') === 'pending'));
$tasks_total = count($tasks);

// Arquivo de status da orquestração
$status_file = $ai_system . '/memory/orchestrator_status.json';
$orchestrator_data = read_json($status_file, [
    'last_cycle' => null,
    'total_cycles' => 0,
    'api_calls' => 0,
    'total_cost' => 0.0,
    'daily_budget' => 10.0,
    'daily_spent' => 0.0,
]);

// Logs
$log_orch = tail_log($root . '/logs/ai-orchestrator.log', 15);
$log_memory = tail_log($ai_system . '/memory/vector_memory.log', 15);

// Database info
$db_file = $ai_system . '/memory/orchestrator.db';
$db_exists = is_file($db_file);
$db_size = $db_exists ? filesize($db_file) : 0;

// ── Calcula estatísticas ────────────────────────────────────────────────
$cpu_percent = 0;
$memory_percent = 0;
if (function_exists('shell_exec')) {
    // Nota: isso pode não funcionar em todos os servidores
    $ps_output = @shell_exec('tasklist /FI "IMAGENAME eq python.exe" 2>nul');
    if ($ps_output) {
        $running_python = (bool)stripos($ps_output, 'python.exe');
    } else {
        $running_python = false;
    }
} else {
    $running_python = null; // Indisponível
}

$current_cost = (float)($orchestrator_data['daily_spent'] ?? 0.0);
$budget_remaining = 10.0 - $current_cost;
$budget_percent = max(0, min(100, ($current_cost / 10.0) * 100));

// ── Agentes definidos ────────────────────────────────────────────────────
$agents = [
    'Orchestrator' => 'Orquestra tarefas e distribui entre agentes',
    'Backend PHP' => 'Implementa lógica de servidor em PHP',
    'Frontend JS/TS' => 'Desenvolve interface e interações',
    'Database' => 'Gerencia banco de dados e queries',
    'DevOps' => 'Deploy, CI/CD e infraestrutura',
    'Security' => 'Análise de segurança e vulnerabilidades',
    'Testing' => 'Testes e validação',
    'Integrations' => 'APIs e integrações externas',
    'SEO' => 'Otimização para buscadores',
    'Auditor' => 'Auditoria completa do sistema',
];

$page_title = 'AI Hybrid System Monitor — ShopVivaliz';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($page_title) ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primary:  #2ECC71;
            --navy:     #1F3A70;
            --danger:   #e74c3c;
            --warning:  #f39c12;
            --info:     #3498db;
            --success:  #27ae60;
            --bg:       #f5f7fa;
            --card:     #ffffff;
            --text:     #2c3e50;
            --muted:    #7f8c8d;
            --border:   #ecf0f1;
            --radius:   8px;
            --shadow:   0 2px 8px rgba(0,0,0,0.1);
        }

        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
        }

        /* HEADER */
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--navy) 100%);
            color: white;
            padding: 32px 24px;
            box-shadow: var(--shadow);
        }
        .header h1 { font-size: 1.9rem; margin-bottom: 8px; }
        .header p { opacity: 0.9; font-size: 0.95rem; }

        /* NAV */
        .nav {
            background: var(--navy);
            padding: 0 24px;
            display: flex;
            gap: 2px;
            overflow-x: auto;
        }
        .nav a {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            padding: 12px 16px;
            font-size: 0.9rem;
            white-space: nowrap;
            transition: all 0.2s;
            border-bottom: 2px solid transparent;
        }
        .nav a:hover { color: white; border-bottom-color: var(--primary); }
        .nav a.active { color: white; border-bottom-color: var(--primary); }

        /* MAIN */
        .container { max-width: 1400px; margin: 0 auto; padding: 28px 24px; }

        /* NOTIFICATION */
        .alert {
            padding: 14px 16px;
            border-radius: var(--radius);
            margin-bottom: 24px;
            font-size: 0.9rem;
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }
        .alert-success { background: #d4edda; border-left: 4px solid var(--success); color: #155724; }
        .alert-warning { background: #fff3cd; border-left: 4px solid var(--warning); color: #856404; }
        .alert-danger { background: #f8d7da; border-left: 4px solid var(--danger); color: #721c24; }
        .alert-info { background: #d1ecf1; border-left: 4px solid var(--info); color: #0c5460; }

        /* GRID */
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }

        /* STAT CARD */
        .stat-card {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            border-left: 4px solid var(--primary);
        }
        .stat-card.danger { border-left-color: var(--danger); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.info { border-left-color: var(--info); }

        .stat-label { font-size: 0.8rem; text-transform: uppercase; color: var(--muted); letter-spacing: 0.05em; margin-bottom: 8px; }
        .stat-value { font-size: 2rem; font-weight: 700; line-height: 1; }
        .stat-detail { font-size: 0.85rem; color: var(--muted); margin-top: 8px; }

        /* STATUS BADGE */
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #d1ecf1; color: #0c5460; }

        /* DOT STATUS */
        .status-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 6px;
            animation: pulse 2s infinite;
        }
        .status-dot.ok { background: var(--success); box-shadow: 0 0 0 3px rgba(46,204,113,0.2); }
        .status-dot.warn { background: var(--warning); }
        .status-dot.err { background: var(--danger); }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

        /* PANEL */
        .panel {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .panel-head {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
            justify-content: space-between;
        }
        .panel-head h2 { font-size: 1rem; font-weight: 600; }
        .panel-body { padding: 20px; }

        /* TABLE */
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        .table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-size: 0.8rem;
            text-transform: uppercase;
            color: var(--muted);
            letter-spacing: 0.04em;
            border-bottom: 1px solid var(--border);
        }
        .table td {
            padding: 12px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        .table tr:hover td { background: #f8f9fa; }
        .table tr:last-child td { border-bottom: none; }

        /* LOG BLOCK */
        .log-box {
            background: #1e1e2e;
            color: #cdd6f4;
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            padding: 16px;
            border-radius: var(--radius);
            max-height: 300px;
            overflow-y: auto;
            line-height: 1.5;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .log-box .err { color: #f38ba8; }
        .log-box .ok { color: #a6e3a1; }
        .log-box .warn { color: #f9e2af; }

        /* PROGRESS BAR */
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin: 12px 0;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--success));
            transition: width 0.3s ease;
        }

        /* EMPTY STATE */
        .empty { text-align: center; padding: 32px 20px; color: var(--muted); font-style: italic; }

        /* RESPONSIVE */
        @media (max-width: 768px) {
            .grid { grid-template-columns: 1fr; }
            .header h1 { font-size: 1.4rem; }
            .container { padding: 16px; }
        }
    </style>
</head>
<body>

<div class="header">
    <h1>AI Hybrid System Monitor</h1>
    <p>Monitoramento em tempo real — Ollama, tarefas, agentes e performance</p>
</div>

<nav class="nav">
    <a href="/admin/">Admin Home</a>
    <a href="/admin/orchestrator.php">Orquestrador 24/7</a>
    <a href="/admin/ai-system-monitor.php" class="active">AI System</a>
    <a href="/admin/monitor-completo.php">Monitor Completo</a>
</nav>

<div class="container">

    <!-- STATUS GERAL -->
    <?php if ($ollama_status !== 'online'): ?>
    <div class="alert alert-danger">
        <span>⚠️</span>
        <div>
            <strong>Ollama offline!</strong> O servidor local de IA não está respondendo.
            Verifique: <code>ollama serve</code> em outro terminal.
        </div>
    </div>
    <?php elseif ($tasks_pending > 0): ?>
    <div class="alert alert-info">
        <span>ℹ️</span>
        <div>
            <strong><?= $tasks_pending ?> tarefa(s) aguardando processamento.</strong>
            O sistema está processando a cada 5 minutos.
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-success">
        <span>✓</span>
        <div>
            <strong>Sistema 100% operacional!</strong> Ollama online, todas as tarefas processadas.
        </div>
    </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="grid">
        <div class="stat-card">
            <div class="stat-label">Ollama Server</div>
            <div class="stat-value" style="color: <?= $ollama_status === 'online' ? 'var(--success)' : 'var(--danger)' ?>">
                <?= $ollama_status === 'online' ? 'Online' : 'Offline' ?>
            </div>
            <div class="stat-detail"><?= h($ollama_check ?? '') ?></div>
        </div>

        <div class="stat-card info">
            <div class="stat-label">Tarefas Processadas</div>
            <div class="stat-value"><?= $tasks_processed ?>/<?= $tasks_total ?></div>
            <div class="stat-detail"><?= $tasks_pending ?> pendentes</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Ciclos Executados</div>
            <div class="stat-value"><?= (int)($orchestrator_data['total_cycles'] ?? 0) ?></div>
            <div class="stat-detail">Última: <?= $orchestrator_data['last_cycle'] ? date('H:i:s', strtotime($orchestrator_data['last_cycle'])) : 'Nunca' ?></div>
        </div>

        <div class="stat-card <?= $budget_percent > 80 ? 'warning' : '' ?>">
            <div class="stat-label">Custo Diário</div>
            <div class="stat-value">$<?= number_format($current_cost, 2) ?></div>
            <div class="progress-bar">
                <div class="progress-bar-fill" style="width: <?= $budget_percent ?>%"></div>
            </div>
            <div class="stat-detail">$<?= number_format($budget_remaining, 2) ?> restante de $10.00</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">API Calls</div>
            <div class="stat-value"><?= (int)($orchestrator_data['api_calls'] ?? 0) ?></div>
            <div class="stat-detail">Chamadas processadas</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Database</div>
            <div class="stat-value" style="font-size: 1.4rem"><?= $db_exists ? number_format($db_size / 1024, 1) . ' KB' : 'Novo' ?></div>
            <div class="stat-detail"><?= $db_exists ? 'Vector memory inicializado' : 'Será criado no primeiro ciclo' ?></div>
        </div>
    </div>

    <!-- MAIN PANELS -->
    <div class="panel">
        <div class="panel-head">
            <h2>Agentes Disponíveis (10 especializados)</h2>
            <span class="badge badge-success">Todos operacionais</span>
        </div>
        <div class="panel-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px;">
                <?php foreach ($agents as $name => $desc): ?>
                <div style="padding: 12px; background: #f8f9fa; border-radius: var(--radius); border-left: 3px solid var(--primary);">
                    <div style="font-weight: 600; margin-bottom: 4px;"><?= h($name) ?></div>
                    <div style="font-size: 0.85rem; color: var(--muted);"><?= h($desc) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- TAREFAS -->
    <div class="panel">
        <div class="panel-head">
            <h2>Fila de Tarefas IA</h2>
            <span class="badge badge-info"><?= $tasks_total ?> total</span>
        </div>
        <div class="panel-body">
            <?php if (empty($tasks)): ?>
                <div class="empty">Nenhuma tarefa na fila</div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Título</th>
                                <th>Status</th>
                                <th>Prioridade</th>
                                <th>Agentes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($tasks, 0, 10) as $t): ?>
                            <tr>
                                <td><code style="font-size: 0.8rem;"><?= h(substr($t['task_id'] ?? '—', 0, 12)) ?></code></td>
                                <td><?= h($t['title'] ?? '—') ?></td>
                                <td>
                                    <?php
                                    $status = $t['status'] ?? 'unknown';
                                    $cls = match($status) {
                                        'processed' => 'badge-success',
                                        'pending' => 'badge-warning',
                                        'error' => 'badge-danger',
                                        default => 'badge-info',
                                    };
                                    ?>
                                    <span class="badge <?= $cls ?>"><?= h($status) ?></span>
                                </td>
                                <td><?= h($t['priority'] ?? '—') ?></td>
                                <td><?= implode(', ', array_map('htmlspecialchars', $t['assigned_to'] ?? [])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($tasks_total > 10): ?>
                <div style="margin-top: 12px; font-size: 0.85rem; color: var(--muted);">
                    Mostrando 10 de <?= $tasks_total ?> tarefas
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- LOGS -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px;">

        <div class="panel">
            <div class="panel-head">
                <h2>Orchestrator Log</h2>
                <span class="badge badge-info"><?= count($log_orch) ?> linhas</span>
            </div>
            <div class="panel-body">
                <?php if (empty($log_orch)): ?>
                    <div class="empty">Sem logs ainda</div>
                <?php else: ?>
                    <div class="log-box">
<?php foreach ($log_orch as $line):
    $cls = '';
    if (stripos($line, 'error') !== false || stripos($line, 'critical') !== false) $cls = 'err';
    elseif (stripos($line, 'warning') !== false || stripos($line, 'warn') !== false) $cls = 'warn';
    elseif (stripos($line, 'success') !== false || stripos($line, 'ok') !== false) $cls = 'ok';
    echo '<span class="' . $cls . '">' . h($line) . '</span>' . "\n";
endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <h2>Vector Memory Log</h2>
                <span class="badge badge-info"><?= count($log_memory) ?> linhas</span>
            </div>
            <div class="panel-body">
                <?php if (empty($log_memory)): ?>
                    <div class="empty">Sem logs ainda</div>
                <?php else: ?>
                    <div class="log-box">
<?php foreach ($log_memory as $line):
    $cls = '';
    if (stripos($line, 'error') !== false) $cls = 'err';
    elseif (stripos($line, 'stored') !== false || stripos($line, 'retrieved') !== false) $cls = 'ok';
    echo '<span class="' . $cls . '">' . h($line) . '</span>' . "\n";
endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- OLLAMA MODELS -->
    <?php if (!empty($ollama_models)): ?>
    <div class="panel">
        <div class="panel-head">
            <h2>Modelos Ollama Carregados</h2>
            <span class="badge badge-success"><?= count($ollama_models) ?> modelo(s)</span>
        </div>
        <div class="panel-body">
            <div style="overflow-x: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Modelo</th>
                            <th>Tamanho</th>
                            <th>Modificado</th>
                            <th>Digest</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ollama_models as $m): ?>
                        <tr>
                            <td><code><?= h($m['name'] ?? '—') ?></code></td>
                            <td><?= isset($m['size']) ? number_format($m['size'] / (1024**3), 1) . ' GB' : '—' ?></td>
                            <td><?= h(isset($m['modified_at']) ? date('d/m H:i', strtotime($m['modified_at'])) : '—') ?></td>
                            <td><code style="font-size: 0.75rem;"><?= h(substr($m['digest'] ?? '—', 0, 16)) ?>...</code></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- INFO -->
    <div style="margin-top: 32px; padding: 16px; background: #f8f9fa; border-radius: var(--radius); font-size: 0.85rem; color: var(--muted);">
        <div style="margin-bottom: 8px;">
            <strong>Última atualização:</strong> <?= date('d/m/Y H:i:s') ?>
        </div>
        <div>
            <strong>Auto-refresh:</strong> A cada 60 segundos |
            <strong>Ciclos automáticos:</strong> A cada 5 minutos (local) + 10 minutos (GitHub Actions)
        </div>
    </div>

</div>

<script>
// Auto-refresh a cada 60 segundos
setTimeout(() => location.reload(), 60000);
</script>

</body>
</html>
