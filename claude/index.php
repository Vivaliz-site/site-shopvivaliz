<?php
/**
 * EHA Status Dashboard — dev.shopvivaliz.com.br/claude
 */
header('Content-Type: text/html; charset=utf-8');

$report_dir  = dirname(__DIR__) . '/automation/eha/reports';
$last_run    = @json_decode(@file_get_contents($report_dir . '/last_run.json'), true) ?: [];
$last_status = trim(@file_get_contents($report_dir . '/last_status.txt') ?: 'UNKNOWN');
$eha_log_lines = @file($report_dir . '/eha.log') ?: [];
$recent_log  = array_slice($eha_log_lines, -30);

// Histórico de execuções
$history_lines = @file($report_dir . '/run_history.jsonl') ?: [];
$history = [];
foreach (array_slice($history_lines, -20) as $line) {
    $rec = @json_decode(trim($line), true);
    if ($rec) $history[] = $rec;
}
$history = array_reverse($history); // mais recente primeiro

$status_color = match($last_status) {
    'READY_FOR_PRODUCTION' => '#22c55e',
    'BLOCKED'              => '#ef4444',
    'ROLLBACK'             => '#f97316',
    default                => '#94a3b8',
};

$action  = $last_run['action'] ?? '—';
$elapsed = $last_run['elapsed_s'] ?? '—';
$metrics = $last_run['metrics'] ?? [];
$run_id  = $last_run['run_id'] ?? '—';
$ts      = $last_run['validation']['timestamp'] ?? ($metrics['timestamp'] ?? '—');

function status_dot(string $status): string {
    $color = match($status) {
        'READY_FOR_PRODUCTION' => '#22c55e',
        'BLOCKED'              => '#ef4444',
        'ROLLBACK'             => '#f97316',
        default                => '#94a3b8',
    };
    return "<span title=\"$status\" style=\"display:inline-block;width:10px;height:10px;border-radius:50%;background:$color\"></span>";
}

function ok_icon(bool $ok): string {
    return $ok
        ? '<span style="color:#22c55e">&#10003;</span>'
        : '<span style="color:#ef4444">&#10007;</span>';
}
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
        h2 { font-size: 1rem; font-weight: 600; margin-bottom: .75rem; color: #94a3b8; }
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
        .sparkline { display: flex; gap: 4px; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 2rem; font-size: .82rem; }
        th { text-align: left; padding: .5rem .75rem; color: #64748b; font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; border-bottom: 1px solid #1e293b; }
        td { padding: .45rem .75rem; border-bottom: 1px solid #1e293b; }
        tr:hover td { background: #1e293b; }
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

    <?php if (!empty($history)): ?>
    <h2>Histórico — últimas <?= count($history) ?> execuções</h2>
    <div class="sparkline">
        <?php foreach (array_reverse($history) as $h): ?>
            <?= status_dot($h['status'] ?? 'UNKNOWN') ?>
        <?php endforeach; ?>
    </div>
    <table>
        <thead>
            <tr>
                <th>Run</th>
                <th>Horário (UTC)</th>
                <th>Status</th>
                <th>Ação</th>
                <th>Tempo</th>
                <th>Checkout</th>
                <th>API</th>
                <th>DB</th>
                <th>E2E</th>
                <th>Erros</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($history as $h): ?>
            <tr>
                <td>#<?= htmlspecialchars((string)($h['run_id'] ?? '—')) ?></td>
                <td style="white-space:nowrap;font-size:.75rem;color:#64748b"><?= htmlspecialchars(substr($h['ts'] ?? '—', 0, 19)) ?></td>
                <td><?php
                    $s = $h['status'] ?? 'UNKNOWN';
                    $c = match($s) {
                        'READY_FOR_PRODUCTION' => '#22c55e',
                        'BLOCKED'              => '#ef4444',
                        'ROLLBACK'             => '#f97316',
                        default                => '#94a3b8',
                    };
                    echo "<span style=\"color:$c;font-weight:600\">" . htmlspecialchars($s) . "</span>";
                ?></td>
                <td><?= htmlspecialchars($h['action'] ?? '—') ?></td>
                <td><?= htmlspecialchars((string)($h['elapsed_s'] ?? '—')) ?>s</td>
                <td><?= ok_icon((bool)($h['checkout_ok'] ?? false)) ?></td>
                <td><?= ok_icon((bool)($h['api_ok'] ?? false)) ?></td>
                <td><?= ok_icon((bool)($h['db_ok'] ?? false)) ?></td>
                <td><?= ($h['e2e_failed'] ?? false)
                    ? '<span style="color:#ef4444">&#10007;</span>'
                    : '<span style="color:#22c55e">&#10003;</span>' ?></td>
                <td><?= (int)($h['error_count'] ?? 0) > 0
                    ? '<span style="color:#ef4444">' . (int)$h['error_count'] . '</span>'
                    : '<span style="color:#22c55e">0</span>' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

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
</body>
</html>
