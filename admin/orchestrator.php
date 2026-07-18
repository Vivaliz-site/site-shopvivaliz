<?php
/**
 * Orquestrador 24/7 — Painel de Controle
 * ShopVivaliz Admin
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';

// ── Bootstrap .env ─────────────────────────────────────────────────────────────
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

function orch_env(string $key): string { return (string)(getenv($key) ?: ''); }

// ── CSRF simples (session token) ───────────────────────────────────────────────
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['orch_csrf'])) {
    $_SESSION['orch_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['orch_csrf'];

// ── Helpers ────────────────────────────────────────────────────────────────────
function orch_read_json(string $path, array $fallback = []): array
{
    if (!is_file($path)) return $fallback;
    $c = @file_get_contents($path);
    if ($c === false || trim($c) === '') return $fallback;
    $d = json_decode($c, true);
    return is_array($d) ? $d : $fallback;
}

function orch_tail_log(string $path, int $n = 20): array
{
    if (!is_file($path)) return [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return is_array($lines) ? array_slice($lines, -$n) : [];
}

// ── Ação POST ─────────────────────────────────────────────────────────────────
$action_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf = trim($_POST['csrf'] ?? '');
    $task        = trim($_POST['task'] ?? '');

    if (!hash_equals($csrf, $posted_csrf)) {
        $action_result = ['ok' => false, 'error' => 'CSRF inválido. Recarregue a página.'];
    } elseif (!in_array($task, ['watchdog', 'report', 'check_prices'], true)) {
        $action_result = ['ok' => false, 'error' => "Tarefa desconhecida: $task"];
    } else {
        $dispatcher = dirname(__DIR__) . '/api/agent/cron-dispatcher.php';
        $php = PHP_BINARY ?: 'php';
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($dispatcher) . ' ' . escapeshellarg($task) . ' 2>&1';
        $body = @shell_exec($cmd);
        if (!is_string($body) || trim($body) === '') {
            $action_result = ['ok' => false, 'error' => 'Falha ao chamar cron-dispatcher.'];
        } else {
            $decoded = json_decode($body, true);
            $action_result = is_array($decoded) ? $decoded : ['ok' => true, 'raw' => substr($body, 0, 500)];
            $action_result['__task'] = $task;
        }
    }
}

// ── Dados ─────────────────────────────────────────────────────────────────────
$root = dirname(__DIR__);

// Fila do orquestrador
$queue_path = $root . '/storage/orchestrator/queue.json';
$queue      = orch_read_json($queue_path, []);

// Logs
$log_orch  = orch_tail_log($root . '/logs/orchestrator.log', 20);
$log_cron  = orch_tail_log($root . '/logs/cron-dispatcher.log', 20);

// Watchdog — tenta via HTTP para refletir estado real do servidor
$base_url   = rtrim(orch_env('SITE_URL') ?: 'https://dev.shopvivaliz.com.br', '/');
$watchdog   = orch_read_json($root . '/logs/autonomous-hourly-guardian.json', []);
$report_api = orch_read_json($root . '/logs/autonomous-cycle-report.json', []);

// Status watchdog
$wd_status = is_array($watchdog) ? (string)($watchdog['decision']['reason'] ?? $watchdog['status'] ?? 'unknown') : 'unreachable';
$wd_alerts = is_array($watchdog) ? ($watchdog['alerts'] ?? ($watchdog['actions'] ?? [])) : [];
$wd_ok     = is_array($watchdog) ? !((bool)($watchdog['decision']['stale'] ?? false)) : false;

// Catálogo do relatório
$cat    = is_array($report_api) ? ($report_api['catalog'] ?? []) : [];
$integ  = is_array($report_api) ? ($report_api['integrations'] ?? []) : [];

// Contadores de fila
$q_pending  = count(array_filter($queue, fn($t) => ($t['status'] ?? '') === 'pending'));
$q_running  = count(array_filter($queue, fn($t) => ($t['status'] ?? '') === 'running'));
$q_done     = count(array_filter($queue, fn($t) => ($t['status'] ?? '') === 'done'));
$q_total    = count($queue);

// ── Helpers HTML ───────────────────────────────────────────────────────────────
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function badge_status(string $status): string
{
    return match($status) {
        'pending'     => '<span class="badge badge-warn">pendente</span>',
        'running'     => '<span class="badge badge-info">em execução</span>',
        'done'        => '<span class="badge badge-ok">concluída</span>',
        'failed'      => '<span class="badge badge-err">falhou</span>',
        default       => '<span class="badge">' . h($status) . '</span>',
    };
}

$page_title = 'Orquestrador 24/7 — ShopVivaliz Admin';
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
            --green:  #2ECC71;
            --navy:   #1F3A70;
            --red:    #e74c3c;
            --yellow: #f39c12;
            --blue:   #3498db;
            --bg:     #f0f2f5;
            --card:   #ffffff;
            --text:   #222;
            --muted:  #666;
            --border: #e2e6ea;
            --radius: 8px;
            --shadow: 0 2px 8px rgba(0,0,0,.09);
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
        }

        /* ── HEADER ── */
        .page-header {
            background: linear-gradient(135deg, var(--green) 0%, var(--navy) 100%);
            color: #fff;
            padding: 28px 32px;
        }
        .page-header h1 { font-size: 1.8rem; font-weight: 700; margin-bottom: 4px; }
        .page-header p  { opacity: .85; font-size: .95rem; }

        /* ── NAV ── */
        .page-nav {
            background: var(--navy);
            padding: 0 32px;
            display: flex;
            gap: 4px;
            overflow-x: auto;
        }
        .page-nav a {
            color: rgba(255,255,255,.75);
            text-decoration: none;
            padding: 10px 14px;
            font-size: .88rem;
            white-space: nowrap;
            transition: color .2s;
        }
        .page-nav a:hover, .page-nav a.active { color: #fff; border-bottom: 2px solid var(--green); }

        /* ── LAYOUT ── */
        .wrap { max-width: 1380px; margin: 0 auto; padding: 28px 24px; }

        /* ── NOTIFICATION ── */
        .notif {
            padding: 14px 20px;
            border-radius: var(--radius);
            margin-bottom: 24px;
            font-size: .92rem;
        }
        .notif-ok  { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .notif-err { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }

        /* ── STAT TILES ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 20px 18px;
        }
        .stat-card .label { font-size: .78rem; text-transform: uppercase; color: var(--muted); letter-spacing: .05em; margin-bottom: 8px; }
        .stat-card .value { font-size: 2rem; font-weight: 700; line-height: 1; }
        .stat-card .value.green  { color: var(--green); }
        .stat-card .value.red    { color: var(--red); }
        .stat-card .value.yellow { color: var(--yellow); }
        .stat-card .value.blue   { color: var(--blue); }
        .stat-card .value.navy   { color: var(--navy); }

        /* ── STATUS BAR ── */
        .status-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 16px 20px;
            margin-bottom: 28px;
            flex-wrap: wrap;
        }
        .status-dot {
            width: 14px; height: 14px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .status-dot.ok     { background: var(--green); box-shadow: 0 0 0 3px rgba(46,204,113,.2); }
        .status-dot.warn   { background: var(--yellow); box-shadow: 0 0 0 3px rgba(243,156,18,.2); }
        .status-dot.err    { background: var(--red); box-shadow: 0 0 0 3px rgba(231,76,60,.2); }
        .status-label { font-weight: 600; font-size: 1rem; }
        .status-sub   { color: var(--muted); font-size: .88rem; margin-left: auto; }
        .alert-list   { list-style: none; margin-top: 10px; width: 100%; }
        .alert-list li { background: #fff3cd; border-left: 4px solid var(--yellow); padding: 8px 12px; border-radius: 4px; margin-bottom: 6px; font-size: .88rem; }

        /* ── MAIN GRID ── */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 28px;
        }
        @media (max-width: 860px) { .main-grid { grid-template-columns: 1fr; } }

        /* ── PANELS ── */
        .panel {
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        .panel-head {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .panel-head h2 { font-size: 1rem; font-weight: 600; }
        .panel-body { padding: 0; }
        .panel-full { grid-column: 1 / -1; }

        /* ── TABLE ── */
        .data-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
        .data-table th {
            background: #f8f9fa;
            padding: 10px 14px;
            text-align: left;
            font-size: .78rem;
            text-transform: uppercase;
            color: var(--muted);
            letter-spacing: .04em;
            border-bottom: 1px solid var(--border);
        }
        .data-table td {
            padding: 10px 14px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        .data-table tr:last-child td { border-bottom: none; }
        .data-table tr:hover td { background: #f8fffe; }
        .overflow-wrap { overflow-x: auto; }

        /* ── BADGE ── */
        .badge {
            display: inline-block;
            padding: 2px 9px;
            border-radius: 20px;
            font-size: .78rem;
            font-weight: 600;
            background: #e9ecef;
            color: #555;
        }
        .badge-ok   { background: #d4edda; color: #155724; }
        .badge-warn { background: #fff3cd; color: #856404; }
        .badge-info { background: #cce5ff; color: #004085; }
        .badge-err  { background: #f8d7da; color: #721c24; }

        /* ── LOG ── */
        .log-block {
            font-family: 'Courier New', monospace;
            font-size: .78rem;
            color: #cdd6f4;
            background: #1e1e2e;
            padding: 16px;
            overflow-x: auto;
            max-height: 340px;
            overflow-y: auto;
            line-height: 1.6;
        }
        .log-block .log-line { white-space: pre-wrap; word-break: break-all; }
        .log-block .log-line:hover { background: rgba(255,255,255,.05); }
        .log-block .log-line.warn  { color: #f9e2af; }
        .log-block .log-line.err   { color: #f38ba8; }
        .log-block .log-line.ok    { color: #a6e3a1; }

        /* ── ACTIONS ── */
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px;
            padding: 20px;
        }
        .btn-action {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 4px;
            background: var(--navy);
            color: #fff;
            border: none;
            border-radius: var(--radius);
            padding: 16px 20px;
            cursor: pointer;
            font-family: inherit;
            font-size: .9rem;
            font-weight: 600;
            text-align: left;
            transition: opacity .15s, transform .1s;
        }
        .btn-action:hover   { opacity: .88; transform: translateY(-1px); }
        .btn-action:active  { transform: translateY(0); }
        .btn-action small   { font-weight: 400; font-size: .78rem; opacity: .75; }
        .btn-action.green   { background: var(--green); }
        .btn-action.blue    { background: var(--blue); }
        .btn-action.yellow  { background: var(--yellow); }

        /* ── CATALOG ROW ── */
        .catalog-kpis {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 0;
        }
        .catalog-kpi {
            padding: 18px 16px;
            border-right: 1px solid var(--border);
            text-align: center;
        }
        .catalog-kpi:last-child { border-right: none; }
        .catalog-kpi .kv { font-size: 1.7rem; font-weight: 700; }
        .catalog-kpi .kl { font-size: .75rem; text-transform: uppercase; color: var(--muted); margin-top: 4px; }

        /* ── EMPTY ── */
        .empty { padding: 28px; text-align: center; color: var(--muted); font-style: italic; font-size: .9rem; }

        /* ── REFRESH INDICATOR ── */
        .refresh-info { font-size: .78rem; color: var(--muted); text-align: right; padding-top: 8px; }
    </style>
</head>
<body>

<div class="page-header">
    <h1>Orquestrador 24/7</h1>
    <p>Painel de controle — monitoramento, fila de tarefas e ações manuais</p>
</div>

<nav class="page-nav">
    <a href="/admin/">Central Admin</a>
    <a href="/admin/monitor-completo.php">Monitor</a>
    <a href="/admin/squad-chat.php">Squad Chat</a>
    <a href="/admin/orchestrator.php" class="active">Orquestrador</a>
    <a href="/api/agent/autonomous-watchdog.php" target="_blank" rel="noreferrer">Watchdog JSON</a>
    <a href="/api/agent/autonomous-report.php" target="_blank" rel="noreferrer">Report JSON</a>
</nav>

<div class="wrap">

<?php if ($action_result !== null): ?>
    <?php
    $notif_ok  = (bool)($action_result['ok'] ?? (!isset($action_result['error'])));
    $notif_cls = $notif_ok ? 'notif-ok' : 'notif-err';
    $task_done = $action_result['__task'] ?? '';
    ?>
    <div class="notif <?= $notif_cls ?>">
        <?php if ($notif_ok): ?>
            <strong>Tarefa executada com sucesso.</strong>
            <?= $task_done !== '' ? ' Tarefa: <code>' . h($task_done) . '</code>.' : '' ?>
            Recarregue para ver os logs atualizados.
        <?php else: ?>
            <strong>Erro ao executar tarefa:</strong> <?= h($action_result['error'] ?? 'Erro desconhecido') ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- ── STATUS GERAL ──────────────────────────────────────────────────────── -->
<div class="status-bar">
    <div class="status-dot <?= $wd_ok ? 'ok' : (count($wd_alerts) > 0 ? 'warn' : 'err') ?>"></div>
    <span class="status-label">
        <?php if ($wd_status === 'unreachable'): ?>
            Watchdog inacessível
        <?php elseif ($wd_ok): ?>
            Tudo OK — sistema operacional
        <?php else: ?>
            Atenção — <?= count($wd_alerts) ?> alerta<?= count($wd_alerts) !== 1 ? 's' : '' ?> detectado<?= count($wd_alerts) !== 1 ? 's' : '' ?>
        <?php endif; ?>
    </span>
    <span class="status-sub">Status: <code><?= h($wd_status) ?></code> · Atualizado em <?= date('H:i:s') ?></span>

    <?php if (!empty($wd_alerts)): ?>
    <ul class="alert-list">
        <?php foreach ($wd_alerts as $al): ?>
            <li><?= h(is_string($al) ? $al : json_encode($al)) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<!-- ── STAT TILES ──────────────────────────────────────────────────────────── -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="label">Total na fila</div>
        <div class="value navy"><?= $q_total ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Pendentes</div>
        <div class="value yellow"><?= $q_pending ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Em execução</div>
        <div class="value blue"><?= $q_running ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Concluídas</div>
        <div class="value green"><?= $q_done ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Alertas Watchdog</div>
        <div class="value <?= count($wd_alerts) > 0 ? 'red' : 'green' ?>"><?= count($wd_alerts) ?></div>
    </div>
    <?php if (!empty($cat)): ?>
    <div class="stat-card">
        <div class="label">Produtos no catálogo</div>
        <div class="value navy"><?= (int)($cat['total'] ?? 0) ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Sem preço</div>
        <div class="value <?= ($cat['zero_price'] ?? 0) > 0 ? 'red' : 'green' ?>"><?= (int)($cat['zero_price'] ?? 0) ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Sem imagem</div>
        <div class="value <?= ($cat['no_image'] ?? 0) > 0 ? 'yellow' : 'green' ?>"><?= (int)($cat['no_image'] ?? 0) ?></div>
    </div>
    <?php endif; ?>
</div>

<!-- ── MAIN GRID ───────────────────────────────────────────────────────────── -->
<div class="main-grid">

    <!-- Fila de tarefas -->
    <div class="panel">
        <div class="panel-head">
            <h2>Fila de Tarefas</h2>
            <?php if ($q_total > 0): ?>
                <span class="badge"><?= $q_total ?> total</span>
            <?php endif; ?>
            <?php if (!is_file($queue_path)): ?>
                <span class="badge badge-warn" style="margin-left:auto;font-size:.72rem;">queue.json não encontrado</span>
            <?php endif; ?>
        </div>
        <div class="panel-body">
            <?php if (empty($queue)): ?>
                <div class="empty">Nenhuma tarefa na fila (storage/orchestrator/queue.json vazio ou ausente)</div>
            <?php else: ?>
                <div class="overflow-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Tarefa</th>
                                <th>Status</th>
                                <th>Prioridade</th>
                                <th>Criada em</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($queue as $t): ?>
                                <tr>
                                    <td><code><?= h(substr((string)($t['id'] ?? '—'), 0, 8)) ?></code></td>
                                    <td><?= h((string)($t['title'] ?? $t['task'] ?? '—')) ?></td>
                                    <td><?= badge_status((string)($t['status'] ?? '')) ?></td>
                                    <td><?= h((string)($t['priority'] ?? '—')) ?></td>
                                    <td style="white-space:nowrap;color:var(--muted);font-size:.82rem;">
                                        <?= h((string)($t['created_at'] ?? $t['queued_at'] ?? '—')) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Relatório do catálogo -->
    <div class="panel">
        <div class="panel-head">
            <h2>Relatório do Catálogo</h2>
            <?php if ($report_api === null): ?>
                <span class="badge badge-err" style="margin-left:auto;">API inacessível</span>
            <?php endif; ?>
        </div>
        <div class="panel-body">
            <?php if ($report_api === null): ?>
                <div class="empty">Não foi possível carregar /api/agent/autonomous-report.php</div>
            <?php else: ?>
                <div class="catalog-kpis">
                    <div class="catalog-kpi">
                        <div class="kv" style="color:var(--navy)"><?= (int)($cat['total'] ?? 0) ?></div>
                        <div class="kl">Total</div>
                    </div>
                    <div class="catalog-kpi">
                        <div class="kv" style="color:<?= ($cat['zero_price'] ?? 0) > 0 ? 'var(--red)' : 'var(--green)' ?>">
                            <?= (int)($cat['zero_price'] ?? 0) ?>
                        </div>
                        <div class="kl">Sem preço</div>
                    </div>
                    <div class="catalog-kpi">
                        <div class="kv" style="color:<?= ($cat['no_image'] ?? 0) > 0 ? 'var(--yellow)' : 'var(--green)' ?>">
                            <?= (int)($cat['no_image'] ?? 0) ?>
                        </div>
                        <div class="kl">Sem imagem</div>
                    </div>
                    <div class="catalog-kpi">
                        <div class="kv" style="color:<?= ($integ['ml_oauth_connected'] ?? false) ? 'var(--green)' : 'var(--red)' ?>">
                            <?= ($integ['ml_oauth_connected'] ?? false) ? 'Sim' : 'Não' ?>
                        </div>
                        <div class="kl">ML conectado</div>
                    </div>
                </div>

                <?php if (!empty($integ)): ?>
                <div style="padding: 16px 20px; border-top: 1px solid var(--border);">
                    <p style="font-size:.82rem;color:var(--muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.04em;">Integrações</p>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.85rem;">
                        <?php foreach ($integ as $ikey => $ival): ?>
                            <?php $iv = is_bool($ival) ? ($ival ? 'OK' : 'Não') : h((string)$ival); ?>
                            <?php $ic = is_bool($ival) ? ($ival ? 'var(--green)' : 'var(--red)') : 'var(--navy)'; ?>
                            <div>
                                <span style="color:var(--muted)"><?= h($ikey) ?>:</span>
                                <strong style="color:<?= $ic ?>"><?= $iv ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Log orchestrator.log -->
    <div class="panel">
        <div class="panel-head">
            <h2>logs/orchestrator.log</h2>
            <span class="badge" style="margin-left:auto"><?= count($log_orch) ?> linhas</span>
        </div>
        <div class="panel-body">
            <?php if (empty($log_orch)): ?>
                <div class="empty">Arquivo vazio ou não encontrado</div>
            <?php else: ?>
                <div class="log-block">
                    <?php foreach ($log_orch as $line): ?>
                        <?php
                        $cls = '';
                        if (stripos($line, 'error') !== false || stripos($line, 'fatal') !== false) $cls = 'err';
                        elseif (stripos($line, 'warn') !== false || stripos($line, 'alerta') !== false) $cls = 'warn';
                        elseif (stripos($line, 'ok') !== false || stripos($line, 'success') !== false) $cls = 'ok';
                        ?>
                        <div class="log-line <?= $cls ?>"><?= h($line) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Log cron-dispatcher.log -->
    <div class="panel">
        <div class="panel-head">
            <h2>logs/cron-dispatcher.log</h2>
            <span class="badge" style="margin-left:auto"><?= count($log_cron) ?> linhas</span>
        </div>
        <div class="panel-body">
            <?php if (empty($log_cron)): ?>
                <div class="empty">Arquivo vazio ou não encontrado</div>
            <?php else: ?>
                <div class="log-block">
                    <?php foreach ($log_cron as $line): ?>
                        <?php
                        $cls = '';
                        if (stripos($line, 'error') !== false || stripos($line, 'fatal') !== false) $cls = 'err';
                        elseif (stripos($line, 'warn') !== false || stripos($line, 'alerta') !== false) $cls = 'warn';
                        elseif (stripos($line, 'ok') !== false || stripos($line, 'fim') !== false) $cls = 'ok';
                        ?>
                        <div class="log-line <?= $cls ?>"><?= h($line) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /.main-grid -->

<!-- ── AÇÕES ──────────────────────────────────────────────────────────────── -->
<div class="panel panel-full" style="margin-bottom:28px;">
    <div class="panel-head">
        <h2>Ações Manuais</h2>
        <span style="margin-left:auto;font-size:.8rem;color:var(--muted)">Chamam o cron-dispatcher.php via HTTP com CRON_SECRET</span>
    </div>
    <div class="panel-body">
        <div class="actions-grid">

            <form method="POST">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="task" value="watchdog">
                <button type="submit" class="btn-action green">
                    Rodar Watchdog agora
                    <small>Verifica todos os endpoints e gera alertas</small>
                </button>
            </form>

            <form method="POST">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="task" value="report">
                <button type="submit" class="btn-action blue">
                    Gerar Relatório agora
                    <small>Consolida dados de catálogo e integrações</small>
                </button>
            </form>

            <form method="POST">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="task" value="check_prices">
                <button type="submit" class="btn-action yellow">
                    Verificar Preços
                    <small>Identifica produtos com preço zerado</small>
                </button>
            </form>

        </div>
    </div>
</div>

<p class="refresh-info">
    Auto-refresh em <span id="countdown">60</span>s ·
    Gerado em <?= date('d/m/Y H:i:s') ?> ·
    <a href="" style="color:var(--navy)">Recarregar agora</a>
</p>

</div><!-- /.wrap -->

<script>
(function () {
    let s = 60;
    const el = document.getElementById('countdown');
    const t = setInterval(() => {
        s--;
        if (el) el.textContent = s;
        if (s <= 0) { clearInterval(t); location.reload(); }
    }, 1000);
})();
</script>

</body>
</html>
