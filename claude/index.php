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
$eha_log_lines   = @file($report_dir . '/eha.log') ?: [];
$recent_log      = array_slice($eha_log_lines, -30);

// Parse run history for trend (last 20 runs)
$history_lines = @file($report_dir . '/run_history.jsonl') ?: [];
$history = [];
foreach (array_slice($history_lines, -20) as $line) {
    $h = @json_decode(trim($line), true);
    if ($h) $history[] = $h;
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

$status_color = match($last_status) {
    'READY_FOR_PRODUCTION' => '#22c55e',
    'BLOCKED'              => '#ef4444',
    'ROLLBACK'             => '#f97316',
    default                => '#94a3b8',
};

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

// Trend sparkline data
$trend_ok  = array_map(fn($h) => ($h['metrics']['checkout_ok'] ?? false) ? 1 : 0, $history);
$trend_err = array_map(fn($h) => (int)($h['metrics']['error_count'] ?? 0), $history);
$sparkline_ok  = implode(',', $trend_ok);
$sparkline_err = implode(',', $trend_err);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EHA — CI Autônomo Contínuo</title>
    <meta http-equiv="refresh" content="60">
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
    </style>
</head>
<body>
    <h1>EHA — CI Autônomo Contínuo</h1>
    <p class="sub">Atualiza a cada 60s &nbsp;·&nbsp; EHA Run #<?= htmlspecialchars((string)$run_id) ?> &nbsp;·&nbsp; <?= htmlspecialchars((string)$ts) ?></p>

    <div class="badge"><?= htmlspecialchars($last_status) ?></div>

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

    <div class="section">
        <h2>Log recente</h2>
        <div class="log"><?php
            foreach (array_reverse($recent_log) as $line) {
                $safe = htmlspecialchars(rtrim($line));
                if (str_contains($line, 'DECISION') || str_contains($line, 'VALIDATION') || str_contains($line, 'ROLLBACK')) {
                    echo "<span class=\"hi\">$safe</span>\n";
                } else {
                    echo "$safe\n";
                }
            }
        ?></div>
    </div>
</body>
</html>
