<?php
/**
 * EHA Status Dashboard — dev.shopvivaliz.com.br/claude
 */
header('Content-Type: text/html; charset=utf-8');

$report_dir = dirname(__DIR__) . '/automation/eha/reports';
$last_run   = @json_decode(@file_get_contents($report_dir . '/last_run.json'), true) ?: [];
$last_status = @file_get_contents($report_dir . '/last_status.txt') ?: 'UNKNOWN';
$eha_log_lines = @file($report_dir . '/eha.log') ?: [];
$recent_log = array_slice($eha_log_lines, -30);

$status_color = match($last_status) {
    'READY_FOR_PRODUCTION' => '#22c55e',
    'BLOCKED'              => '#ef4444',
    'ROLLBACK'             => '#f97316',
    default                => '#94a3b8',
};

$action = $last_run['action'] ?? '—';
$elapsed = $last_run['elapsed_s'] ?? '—';
$metrics = $last_run['metrics'] ?? [];
$run_id  = $last_run['run_id'] ?? '—';
$ts      = $last_run['validation']['timestamp'] ?? ($metrics['timestamp'] ?? '—');
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
        .sub { font-size: .85rem; color: #64748b; margin-bottom: 2rem; }
        .badge { display: inline-block; padding: .35rem .9rem; border-radius: 999px; font-weight: 700; font-size: 1rem; color: #fff; background: <?= $status_color ?>; margin-bottom: 1.5rem; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .card { background: #1e293b; border-radius: .75rem; padding: 1rem 1.25rem; }
        .card-label { font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; color: #64748b; margin-bottom: .3rem; }
        .card-value { font-size: 1.15rem; font-weight: 700; }
        .ok   { color: #22c55e; }
        .fail { color: #ef4444; }
        .log  { background: #0f172a; border: 1px solid #1e293b; border-radius: .5rem; padding: 1rem; font-family: monospace; font-size: .78rem; max-height: 400px; overflow-y: auto; white-space: pre-wrap; color: #94a3b8; }
        .log .hi { color: #f8fafc; }
    </style>
</head>
<body>
    <h1>EHA — CI Autônomo Contínuo</h1>
    <p class="sub">Atualiza a cada 60s &nbsp;·&nbsp; Run #<?= htmlspecialchars((string)$run_id) ?> &nbsp;·&nbsp; <?= htmlspecialchars((string)$ts) ?></p>

    <div class="badge"><?= htmlspecialchars($last_status) ?></div>

    <div class="grid">
        <div class="card">
            <div class="card-label">Ação tomada</div>
            <div class="card-value"><?= htmlspecialchars($action) ?></div>
        </div>
        <div class="card">
            <div class="card-label">Tempo de execução</div>
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

    <h2 style="font-size:1rem;font-weight:600;margin-bottom:.75rem;color:#94a3b8;">Log recente</h2>
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
</body>
</html>
