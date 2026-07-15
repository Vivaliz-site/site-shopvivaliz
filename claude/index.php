<?php
/**
 * EHA Status Dashboard — dev.shopvivaliz.com.br/claude
 */
header('Content-Type: text/html; charset=utf-8');

$report_dir = dirname(__DIR__) . '/automation/eha/reports';

$last_run        = @json_decode(@file_get_contents($report_dir . '/last_run.json'), true) ?: [];
$last_status     = trim(@file_get_contents($report_dir . '/last_status.txt') ?: 'UNKNOWN');
$last_ci_run     = @json_decode(@file_get_contents($report_dir . '/last_ci_run.json'), true) ?: [];
$medusa_run      = @json_decode(@file_get_contents($report_dir . '/medusa-last-run.json'), true) ?: [];
$eha_log_lines   = @file($report_dir . '/eha_events.txt') ?: [];
$recent_log      = array_slice($eha_log_lines, -30);

// Parse run history for trend (last 20 runs)
$history_lines = @file($report_dir . '/run_history.jsonl') ?: [];
$history = [];
foreach (array_slice($history_lines, -20) as $line) {
    $h = @json_decode(trim($line), true);
    if ($h) $history[] = $h;
}

// Calcular taxa de sucesso
$total_runs   = count($history);
$success_runs = count(array_filter($history, fn($h) =>
    ($h['action'] ?? '') !== 'ROLLBACK' && (int)($h['error_count'] ?? 0) === 0
));
$success_rate = $total_runs > 0 ? round($success_runs / $total_runs * 100) : 0;
$success_color = $success_rate >= 90 ? 'ok' : ($success_rate >= 70 ? 'warn' : 'fail');

// Calcular streak de runs consecutivos sem falha
$streak = 0;
foreach (array_reverse($history) as $h) {
    if (($h['action'] ?? '') === 'DEPLOY_OK' && (int)($h['error_count'] ?? 0) === 0 && !($h['e2e_failed'] ?? false)) {
        $streak++;
    } else {
        break;
    }
}

// Agente Proativo — último run
$proactive_log_path = dirname(__DIR__) . '/automation/proactive/logs/runs.jsonl';
$proactive_runs = [];
if (file_exists($proactive_log_path)) {
    foreach (@file($proactive_log_path) ?: [] as $line) {
        $e = @json_decode(trim($line), true);
        if ($e) $proactive_runs[] = $e;
    }
}
$proactive_last = end($proactive_runs) ?: null;
$proactive_ts      = $proactive_last['timestamp'] ?? '—';
$proactive_action  = $proactive_last['action'] ?? '—';
$proactive_file    = $proactive_last['file'] ?? '—';
$proactive_reason  = $proactive_last['reason'] ?? '—';
$proactive_commit  = $proactive_last['commit'] ?? '—';
$proactive_total   = count($proactive_runs);
$proactive_color   = $proactive_last ? '#22c55e' : '#64748b';

// Mercado Livre — status do token OAuth
$ml_token_path = dirname(__DIR__) . '/storage/private/ml-tokens.json';
$ml_tokens     = @json_decode(@file_get_contents($ml_token_path), true) ?: [];
$ml_connected  = !empty($ml_tokens['access_token']);
$ml_user_id    = $ml_tokens['user_id'] ?? null;
$ml_exp_ms     = (int)($ml_tokens['expires_at_ms'] ?? 0);
$ml_now_ms     = (int)(microtime(true) * 1000);
$ml_expires_in = $ml_exp_ms > 0 ? max(0, (int)(($ml_exp_ms - $ml_now_ms) / 1000)) : null;
$ml_has_refresh = !empty($ml_tokens['refresh_token']);
$ml_status_color = $ml_connected ? '#22c55e' : '#64748b';
$ml_exp_label  = $ml_expires_in !== null
    ? ($ml_expires_in > 3600 ? round($ml_expires_in / 3600, 1) . 'h' : round($ml_expires_in / 60) . 'min')
    : '—';
$ml_exp_color  = $ml_expires_in === null ? '#64748b' : ($ml_expires_in < 600 ? '#ef4444' : ($ml_expires_in < 3600 ? '#f59e0b' : '#22c55e'));

$status_color = match($last_status) {
    'READY_FOR_PRODUCTION' => '#22c55e',
    'BLOCKED'              => '#ef4444',
    'ROLLBACK'             => '#f97316',
    default                => '#94a3b8',
};

// Staleness — tempo desde último run (em segundos)
$last_run_ts_raw = $last_run['metrics']['timestamp'] ?? ($last_run['validation']['timestamp'] ?? null);
$last_run_unix   = $last_run_ts_raw ? strtotime($last_run_ts_raw) : 0;
$age_seconds     = $last_run_unix > 0 ? (time() - $last_run_unix) : null;
// Thresholds: CI roda a cada 30 min → warn > 40min, crit > 80min
$staleness_color = $age_seconds === null ? '#64748b' : ($age_seconds < 2400 ? '#22c55e' : ($age_seconds < 4800 ? '#f59e0b' : '#ef4444'));
$staleness_label = $age_seconds === null ? '—' : ($age_seconds < 60 ? 'agora mesmo' : ($age_seconds < 3600 ? round($age_seconds / 60) . ' min atrás' : round($age_seconds / 3600, 1) . 'h atrás'));
$staleness_alert = $age_seconds !== null && $age_seconds >= 4800;

$action   = $last_run['action'] ?? '—';
$elapsed  = $last_run['elapsed_s'] ?? '—';
$metrics  = $last_run['metrics'] ?? [];
$run_id   = $last_run['run_id'] ?? '—';
$ts       = $last_run['validation']['timestamp'] ?? ($metrics['timestamp'] ?? '—');

$ci_run_number = $last_ci_run['run_number'] ?? '—';
$ci_run_url    = $last_ci_run['url'] ?? '#';
$ci_event      = $last_ci_run['event'] ?? '—';
$ci_sha        = substr($last_ci_run['sha'] ?? '', 0, 7) ?: '—';
$ci_ts         = $last_ci_run['timestamp'] ?? '—';

$medusa_status    = $medusa_run['status'] ?? '—';
$medusa_next      = $medusa_run['next_step_title'] ?? '—';
$medusa_applied   = count($medusa_run['applied_actions'] ?? []);
$medusa_color     = $medusa_status === 'completed' ? '#22c55e' : ($medusa_status === 'error' ? '#ef4444' : '#f59e0b');

// Trio IA Executor — fila de tarefas (root tem prioridade; logs/ como fallback)
$tasks_queue_path = dirname(__DIR__) . '/tasks-queue.json';
if (!file_exists($tasks_queue_path)) {
    $tasks_queue_path = dirname(__DIR__) . '/logs/tasks-queue.json';
}
$tasks_data = @json_decode(@file_get_contents($tasks_queue_path), true) ?: ['queue' => []];
$tasks_all        = $tasks_data['queue'] ?? [];
$tasks_completed  = array_values(array_filter($tasks_all, fn($t) => ($t['status'] ?? '') === 'completed'));
$tasks_pending    = array_values(array_filter($tasks_all, fn($t) => ($t['status'] ?? '') === 'pending'));
$tasks_total      = count($tasks_all);
$tasks_pct        = $tasks_total > 0 ? round(count($tasks_completed) / $tasks_total * 100) : 0;
$tasks_color      = empty($tasks_pending) ? '#22c55e' : (count($tasks_completed) > 0 ? '#f59e0b' : '#3b82f6');

// Trend sparkline data — run_history.jsonl usa campos no nível raiz (não aninhados em metrics)
$trend_ok  = array_map(fn($h) => ($h['checkout_ok'] ?? false) ? 1 : 0, $history);
$trend_err = array_map(fn($h) => (int)($h['error_count'] ?? 0), $history);
$sparkline_ok  = implode(',', $trend_ok);
$sparkline_err = implode(',', $trend_err);

// Total de eventos no log
$total_events = count($eha_log_lines);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EHA — CI Autônomo Contínuo</title>
<style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; padding: 2rem; }
        h1 { font-size: 1.5rem; font-weight: 700; margin-bottom: .25rem; }
        h2 { font-size: .95rem; font-weight: 600; margin: 1.5rem 0 .75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: .06em; }
        .sub { font-size: .85rem; color: #64748b; margin-bottom: 1.5rem; }
        .badge { display: inline-block; padding: .35rem .9rem; border-radius: 999px; font-weight: 700; font-size: 1rem; color: #fff; background: <?= $status_color ?>; margin-bottom: 1.5rem; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 1rem; }
        .card { background: #1e293b; border-radius: .75rem; padding: 1rem 1.25rem; }
        .card-label { font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; color: #64748b; margin-bottom: .3rem; }
        .card-value { font-size: 1.1rem; font-weight: 700; word-break: break-all; }
        .ok    { color: #22c55e; }
        .fail  { color: #ef4444; }
        .warn  { color: #f59e0b; }
        .muted { color: #64748b; }
        .log { background: #0f172a; border: 1px solid #1e293b; border-radius: .5rem; padding: 1rem; font-family: monospace; font-size: .78rem; max-height: 350px; overflow-y: auto; white-space: pre-wrap; color: #94a3b8; }
        .log .hi { color: #f8fafc; }
        .ci-link { color: #60a5fa; text-decoration: none; font-size: .85rem; }
        .ci-link:hover { text-decoration: underline; }
        .section { border-top: 1px solid #1e293b; padding-top: 1rem; margin-top: .5rem; }
        .medusa-badge { display: inline-block; padding: .2rem .7rem; border-radius: 999px; font-size: .8rem; font-weight: 600; color: #fff; background: <?= $medusa_color ?>; }
        canvas { display: block; }
        .spark-row { display: flex; gap: 1rem; flex-wrap: wrap; }
        .spark-box { background: #1e293b; border-radius: .75rem; padding: 1rem; flex: 1; min-width: 200px; }
        .spark-label { font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; color: #64748b; margin-bottom: .5rem; }
        .log-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: .5rem; }
        .log-count { font-size: .75rem; color: #64748b; }
        .task-item { background: #1e293b; border-radius: .5rem; padding: .65rem 1rem; margin-bottom: .5rem; display: flex; align-items: flex-start; gap: .75rem; }
        .task-priority { border-radius: 999px; padding: .15rem .55rem; font-size: .7rem; font-weight: 700; flex-shrink: 0; margin-top: .1rem; }
        .priority-high   { background: #ef444422; color: #ef4444; }
        .priority-medium { background: #f59e0b22; color: #f59e0b; }
        .priority-low    { background: #3b82f622; color: #3b82f6; }
        .progress-bar { background: #1e293b; border-radius: 999px; height: 8px; overflow: hidden; margin: .5rem 0 .25rem; }
        .progress-fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg, #3b82f6, #22c55e); transition: width .3s; }
        .staleness-bar { border-radius: .75rem; padding: .75rem 1.25rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 1rem; font-size: .9rem; }
        .staleness-crit { background: #ef444415; border: 1px solid #ef4444; }
        .staleness-ok   { background: #1e293b; }
        .refresh-count  { font-size: .75rem; color: #64748b; margin-left: auto; }
        .log .log-error   { color: #ef4444; }
        .log .log-blocked { color: #f97316; font-weight: 600; }
    </style>
</head>
<body>
    <h1>EHA — CI Autônomo Contínuo</h1>
    <p class="sub">Atualiza a cada 60s &nbsp;·&nbsp; EHA Run #<?= htmlspecialchars((string)$run_id) ?> &nbsp;·&nbsp; <?= htmlspecialchars((string)$ts) ?></p>

    <div class="badge"><?= htmlspecialchars($last_status) ?></div>

    <?php
    // Detecta se CI está parado (runs recentes todos falhando em < 30s = quota exaurida)
    $quota_alert = ($age_seconds !== null && $age_seconds > 7200); // >2h sem run bem-sucedido
    if ($quota_alert): ?>
    <div style="background:#ef444415;border:1px solid #ef4444;border-radius:.75rem;padding:.85rem 1.25rem;margin-bottom:1.25rem;font-size:.88rem;">
        ⚠️ <strong style="color:#ef4444">CI parado</strong> — Último run bem-sucedido há
        <strong style="color:#f87171"><?= htmlspecialchars($staleness_label) ?></strong>.
        Provável causa: <strong>quota GitHub Actions esgotada</strong>.
        Verifique em <a href="https://github.com/settings/billing/summary" target="_blank" style="color:#60a5fa">billing</a>
        e <a href="https://github.com/fredmourao-ai/site-shopvivaliz/settings/actions" target="_blank" style="color:#60a5fa">Actions settings</a>.
        Fix aplicado: watchdog reduzido de <code>*/15</code> → <code>0 */6</code> e CI de <code>*/10</code> → <code>0 */4</code> (economia de ~95% em runs/mês). Deploy voltará após reset da cota mensal.
    </div>
    <?php endif; ?>

    <div class="staleness-bar <?= $staleness_alert ? 'staleness-crit' : 'staleness-ok' ?>">
        <span style="font-size:1.1rem"><?= $staleness_alert ? '⚠️' : '🟢' ?></span>
        <span>
            <strong style="color:<?= $staleness_color ?>">Último run: <span id="js-age"><?= htmlspecialchars($staleness_label) ?></span></strong>
            <?php if ($staleness_alert): ?>
                &nbsp;<span style="color:#ef4444;font-size:.8rem">— CI pode estar parado!</span>
            <?php endif; ?>
        </span>
        <span class="refresh-count">Página recarrega em <span id="js-countdown">60</span>s</span>
    </div>

    <h2>Métricas EHA</h2>
    <div class="grid">
        <div class="card">
            <div class="card-label">Ação tomada</div>
            <div class="card-value"><?= htmlspecialchars($action) ?></div>
        </div>
        <div class="card">
            <div class="card-label">Tempo execução</div>
            <div class="card-value"><?= htmlspecialchars((string)$elapsed) ?>s</div>
        </div>
        <div class="card">
            <div class="card-label">Taxa de sucesso</div>
            <div class="card-value <?= $success_color ?>">
                <?= $success_rate ?>%
                <span style="font-size:.7rem;font-weight:400;color:#64748b">(<?= $success_runs ?>/<?= $total_runs ?>)</span>
            </div>
        </div>
        <div class="card">
            <div class="card-label">Checkout</div>
            <div class="card-value <?= ($metrics['checkout_ok'] ?? false) ? 'ok' : 'fail' ?>">
                <?= ($metrics['checkout_ok'] ?? false) ? 'OK' : 'FALHOU' ?>
            </div>
        </div>
        <div class="card">
            <div class="card-label">API</div>
            <div class="card-value <?= ($metrics['api_ok'] ?? false) ? 'ok' : 'fail' ?>">
                <?= ($metrics['api_ok'] ?? false) ? 'OK' : 'FALHOU' ?>
            </div>
        </div>
        <div class="card">
            <div class="card-label">Banco de dados</div>
            <div class="card-value <?= ($metrics['db_ok'] ?? false) ? 'ok' : 'fail' ?>">
                <?= ($metrics['db_ok'] ?? false) ? 'OK' : 'FALHOU' ?>
            </div>
        </div>
        <div class="card">
            <div class="card-label">Páginas</div>
            <div class="card-value <?= ($metrics['pages_ok'] ?? false) ? 'ok' : 'fail' ?>">
                <?= ($metrics['pages_ok'] ?? false) ? 'OK' : 'FALHOU' ?>
            </div>
        </div>
        <div class="card">
            <div class="card-label">Erros recentes</div>
            <div class="card-value <?= ($metrics['error_count'] ?? 0) > 5 ? 'fail' : 'ok' ?>">
                <?= (int)($metrics['error_count'] ?? 0) ?>
            </div>
        </div>
        <div class="card">
            <div class="card-label">Streak OK</div>
            <div class="card-value <?= $streak >= 10 ? 'ok' : ($streak >= 3 ? 'warn' : 'muted') ?>">
                <?= $streak ?> <span style="font-size:.7rem;font-weight:400;color:#64748b">runs</span>
            </div>
        </div>
        <div class="card">
            <div class="card-label">E2E</div>
            <div class="card-value <?= ($metrics['e2e_failed'] ?? false) ? 'fail' : 'ok' ?>">
                <?= ($metrics['e2e_failed'] ?? false) ? 'FALHOU' : 'OK' ?>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>Último CI Run</h2>
        <div class="grid">
            <div class="card">
                <div class="card-label">Run #</div>
                <div class="card-value">
                    <?php if ($ci_run_url !== '#'): ?>
                        <a class="ci-link" href="<?= htmlspecialchars($ci_run_url) ?>" target="_blank">#<?= htmlspecialchars((string)$ci_run_number) ?></a>
                    <?php else: ?>
                        <?= htmlspecialchars((string)$ci_run_number) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card">
                <div class="card-label">Evento</div>
                <div class="card-value muted"><?= htmlspecialchars($ci_event) ?></div>
            </div>
            <div class="card">
                <div class="card-label">SHA</div>
                <div class="card-value muted"><?= htmlspecialchars($ci_sha) ?></div>
            </div>
            <div class="card">
                <div class="card-label">Timestamp</div>
                <div class="card-value muted" style="font-size:.85rem"><?= htmlspecialchars($ci_ts) ?></div>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>Migração Medusa</h2>
        <div style="margin-bottom:.75rem">
            <span class="medusa-badge"><?= htmlspecialchars(strtoupper($medusa_status)) ?></span>
        </div>
        <div class="grid">
            <div class="card" style="grid-column: span 2">
                <div class="card-label">Próximo passo</div>
                <div class="card-value" style="font-size:.95rem"><?= htmlspecialchars($medusa_next) ?></div>
            </div>
            <div class="card">
                <div class="card-label">Ações aplicadas</div>
                <div class="card-value ok"><?= (int)$medusa_applied ?></div>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>Trio IA — Fila de Tarefas</h2>
        <div style="margin-bottom:.75rem">
            <span style="display:inline-block;padding:.2rem .7rem;border-radius:999px;font-size:.8rem;font-weight:600;color:#fff;background:<?= $tasks_color ?>">
                <?= count($tasks_pending) ?> PENDENTES &nbsp;·&nbsp; <?= count($tasks_completed) ?> COMPLETAS
            </span>
            &nbsp;<span style="font-size:.8rem;color:#64748b"><?= $tasks_pct ?>% concluído</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" style="width:<?= $tasks_pct ?>%"></div>
        </div>
        <div class="grid" style="margin-top:.75rem">
            <div class="card">
                <div class="card-label">Total</div>
                <div class="card-value"><?= $tasks_total ?></div>
            </div>
            <div class="card">
                <div class="card-label">Completas</div>
                <div class="card-value ok"><?= count($tasks_completed) ?></div>
            </div>
            <div class="card">
                <div class="card-label">Pendentes</div>
                <div class="card-value <?= empty($tasks_pending) ? 'ok' : 'warn' ?>"><?= count($tasks_pending) ?></div>
            </div>
        </div>
        <?php if (!empty($tasks_pending)): ?>
        <div style="margin-top:.75rem">
            <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:.5rem">PRÓXIMAS TAREFAS</div>
            <?php foreach (array_slice($tasks_pending, 0, 3) as $t): ?>
            <?php $pri = $t['priority'] ?? 'normal'; ?>
            <div class="task-item">
                <span class="task-priority priority-<?= htmlspecialchars($pri) ?>"><?= htmlspecialchars(strtoupper($pri)) ?></span>
                <div>
                    <div style="font-size:.88rem;font-weight:600;color:#e2e8f0"><?= htmlspecialchars($t['title'] ?? '') ?></div>
                    <div style="font-size:.75rem;color:#64748b;margin-top:.15rem"><?= htmlspecialchars(mb_substr($t['description'] ?? '', 0, 110)) ?>…</div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php elseif ($tasks_total > 0): ?>
        <p style="color:#22c55e;font-size:.85rem;margin-top:.5rem">✓ Todas as tarefas foram concluídas!</p>
        <?php else: ?>
        <p style="color:#64748b;font-size:.85rem;margin-top:.5rem">Nenhuma tarefa na fila. Adicione em <code style="color:#94a3b8">logs/tasks-queue.json</code>.</p>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Agente Proativo</h2>
        <div style="margin-bottom:.75rem">
            <span style="display:inline-block;padding:.2rem .7rem;border-radius:999px;font-size:.8rem;font-weight:600;color:#fff;background:<?= $proactive_color ?>">
                <?= $proactive_last ? 'ATIVO' : 'SEM EXECUÇÕES' ?>
            </span>
            &nbsp;<span style="font-size:.8rem;color:#64748b"><?= htmlspecialchars($proactive_total) ?> runs registrados</span>
        </div>
        <div class="grid">
            <div class="card" style="grid-column:span 2">
                <div class="card-label">Última ação</div>
                <div class="card-value" style="font-size:.9rem"><?= htmlspecialchars($proactive_action) ?></div>
            </div>
            <div class="card">
                <div class="card-label">Timestamp</div>
                <div class="card-value muted" style="font-size:.8rem"><?= htmlspecialchars($proactive_ts) ?></div>
            </div>
            <?php if ($proactive_last && $proactive_action !== 'no_action'): ?>
            <div class="card" style="grid-column:span 2">
                <div class="card-label">Arquivo modificado</div>
                <div class="card-value muted" style="font-size:.85rem"><?= htmlspecialchars($proactive_file) ?></div>
            </div>
            <div class="card" style="grid-column:span 3">
                <div class="card-label">Motivo</div>
                <div class="card-value" style="font-size:.82rem;font-weight:400"><?= htmlspecialchars(mb_substr($proactive_reason, 0, 200)) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="section">
        <h2>Mercado Livre</h2>
        <div style="margin-bottom:.75rem">
            <span style="display:inline-block;padding:.2rem .7rem;border-radius:999px;font-size:.8rem;font-weight:600;color:#fff;background:<?= $ml_status_color ?>">
                <?= $ml_connected ? 'CONECTADO' : 'NÃO CONECTADO' ?>
            </span>
            <?php if (!$ml_connected): ?>
            &nbsp;<a href="/api/ml/login" style="font-size:.8rem;color:#60a5fa">Conectar via OAuth &rarr;</a>
            <?php endif; ?>
        </div>
        <?php if ($ml_connected): ?>
        <div class="grid">
            <div class="card">
                <div class="card-label">User ID</div>
                <div class="card-value muted"><?= htmlspecialchars((string)$ml_user_id) ?></div>
            </div>
            <div class="card">
                <div class="card-label">Token expira em</div>
                <div class="card-value" style="color:<?= $ml_exp_color ?>"><?= htmlspecialchars($ml_exp_label) ?></div>
            </div>
            <div class="card">
                <div class="card-label">Refresh Token</div>
                <div class="card-value <?= $ml_has_refresh ? 'ok' : 'fail' ?>"><?= $ml_has_refresh ? 'Disponível' : 'Ausente' ?></div>
            </div>
            <div class="card">
                <div class="card-label">Ações</div>
                <div class="card-value" style="font-size:.85rem;font-weight:400">
                    <a href="/api/ml/me" style="color:#60a5fa">/me</a> &nbsp;
                    <a href="/api/ml/token" style="color:#60a5fa">status</a>
                </div>
            </div>
        </div>
        <?php else: ?>
        <p style="font-size:.85rem;color:#64748b">Configure <code style="color:#94a3b8">ML_CLIENT_ID</code>, <code style="color:#94a3b8">ML_CLIENT_SECRET</code> e <code style="color:#94a3b8">ML_REDIRECT_URI</code> no .env / GitHub Secrets, depois acesse <a href="/api/ml/login" style="color:#60a5fa">/api/ml/login</a>.</p>
        <?php endif; ?>
    </div>

    <?php if (count($history) > 1): ?>
    <div class="section">
        <h2>Tendência — últimas <?= count($history) ?> execuções</h2>
        <div class="spark-row">
            <div class="spark-box">
                <div class="spark-label">Checkout OK</div>
                <canvas id="sparkOk" height="50"></canvas>
            </div>
            <div class="spark-box">
                <div class="spark-label">Erros por run</div>
                <canvas id="sparkErr" height="50"></canvas>
            </div>
        </div>
    </div>
    <script>
    (function() {
        function spark(id, data, color) {
            var canvas = document.getElementById(id);
            if (!canvas) return;
            canvas.width = canvas.parentElement.clientWidth - 32;
            var ctx = canvas.getContext('2d');
            var h = canvas.height, w = canvas.width;
            var max = Math.max(...data, 1);
            var step = w / (data.length - 1);
            ctx.beginPath();
            data.forEach(function(v, i) {
                var x = i * step;
                var y = h - (v / max) * (h - 4) - 2;
                i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
            });
            ctx.strokeStyle = color;
            ctx.lineWidth = 2;
            ctx.stroke();
            // fill
            ctx.lineTo(w, h); ctx.lineTo(0, h); ctx.closePath();
            ctx.fillStyle = color + '22';
            ctx.fill();
        }
        var ok  = [<?= $sparkline_ok ?>];
        var err = [<?= $sparkline_err ?>];
        spark('sparkOk',  ok,  '#22c55e');
        spark('sparkErr', err, '#ef4444');
    })();
    </script>
    <?php endif; ?>

    <?php if (count($history) > 0): ?>
    <div class="section">
        <h2>Histórico recente</h2>
        <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.82rem">
            <thead>
                <tr style="color:#64748b;text-align:left">
                    <th style="padding:.4rem .75rem;font-weight:600">Run</th>
                    <th style="padding:.4rem .75rem;font-weight:600">Timestamp</th>
                    <th style="padding:.4rem .75rem;font-weight:600">Ação</th>
                    <th style="padding:.4rem .75rem;font-weight:600">Tempo</th>
                    <th style="padding:.4rem .75rem;font-weight:600">Checkout</th>
                    <th style="padding:.4rem .75rem;font-weight:600">E2E</th>
                    <th style="padding:.4rem .75rem;font-weight:600">Erros</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach (array_reverse($history) as $h): ?>
            <?php
                $row_action = $h['action'] ?? '—';
                $row_color  = $row_action === 'DEPLOY_OK' ? '#22c55e' : ($row_action === 'ROLLBACK' ? '#ef4444' : '#f59e0b');
                $row_ts     = isset($h['ts']) ? substr($h['ts'], 0, 16) : '—';
            ?>
            <tr style="border-top:1px solid #1e293b">
                <td style="padding:.4rem .75rem;color:#94a3b8"><?= htmlspecialchars((string)($h['run_id'] ?? '—')) ?></td>
                <td style="padding:.4rem .75rem;color:#64748b;white-space:nowrap"><?= htmlspecialchars($row_ts) ?></td>
                <td style="padding:.4rem .75rem;font-weight:600;color:<?= $row_color ?>"><?= htmlspecialchars($row_action) ?></td>
                <td style="padding:.4rem .75rem;color:#64748b"><?= htmlspecialchars((string)($h['elapsed_s'] ?? '—')) ?>s</td>
                <td style="padding:.4rem .75rem;color:<?= ($h['checkout_ok'] ?? false) ? '#22c55e' : '#ef4444' ?>"><?= ($h['checkout_ok'] ?? false) ? '✓' : '✗' ?></td>
                <td style="padding:.4rem .75rem;color:<?= ($h['e2e_failed'] ?? false) ? '#ef4444' : '#22c55e' ?>"><?= ($h['e2e_failed'] ?? false) ? '✗' : '✓' ?></td>
                <td style="padding:.4rem .75rem;color:<?= ($h['error_count'] ?? 0) > 0 ? '#ef4444' : '#64748b' ?>"><?= (int)($h['error_count'] ?? 0) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="section">
        <h2>Log de eventos
            <span class="log-count">(<?= $total_events ?> linhas totais · exibindo últimas 30)</span>
        </h2>
        <?php if (empty($recent_log)): ?>
            <p style="color:#64748b;font-size:.85rem">Nenhum evento registrado ainda.</p>
        <?php else: ?>
        <div class="log"><?php
            foreach (array_reverse($recent_log) as $line) {
                $safe = htmlspecialchars(rtrim($line));
                if (str_contains($line, 'BLOCKED') || str_contains($line, 'ERROR')) {
                    echo "<span class=\"log-blocked\">$safe</span>\n";
                } elseif (str_contains($line, 'ROLLBACK')) {
                    echo "<span class=\"log-error\">$safe</span>\n";
                } elseif (str_contains($line, 'DECISION') || str_contains($line, 'VALIDATION')) {
                    echo "<span class=\"hi\">$safe</span>\n";
                } else {
                    echo "$safe\n";
                }
            }
        ?></div>
        <?php endif; ?>
    </div>

    <script>
    (function() {
        var lastRunUnix = <?= (int)$last_run_unix ?>;
        var refreshIn   = 60;
        var cdEl  = document.getElementById('js-countdown');
        var ageEl = document.getElementById('js-age');

        function fmtAge(secs) {
            if (secs < 60)   return 'agora mesmo';
            if (secs < 3600) return Math.round(secs / 60) + ' min atrás';
            return (secs / 3600).toFixed(1) + 'h atrás';
        }

        setInterval(function() {
            refreshIn--;
            if (cdEl) cdEl.textContent = Math.max(0, refreshIn) + 's';
            if (ageEl && lastRunUnix > 0) {
                var age = Math.floor(Date.now() / 1000) - lastRunUnix;
                ageEl.textContent = fmtAge(age);
            }
            if (refreshIn <= 0) location.reload();
        }, 1000);
    })();
    </script>
</body>
</html>
