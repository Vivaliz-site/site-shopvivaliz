<?php
/**
 * EHA Status Dashboard — dev.shopvivaliz.com.br/claude
 * Faz health check em tempo real, sem depender de arquivos de report.
 */
header('Content-Type: text/html; charset=utf-8');

$base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
      . '://' . ($_SERVER['HTTP_HOST'] ?? 'dev.shopvivaliz.com.br');

$t0 = microtime(true);

function http_check(string $url, int $timeout = 8): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'EHA-Dashboard/2.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ms   = (int)(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
    curl_close($ch);
    return ['code' => $code, 'ms' => $ms, 'body' => (string)$body];
}

// Health checks em paralelo via múltiplas requisições
$checks = [
    'homepage' => $base . '/claude/',
    'api'      => $base . '/claude/api/health.php',
    'catalogo' => $base . '/claude/catalogo/',
    'carrinho' => $base . '/claude/carrinho/',
];

$results = [];
$mh = curl_multi_init();
$handles = [];

foreach ($checks as $key => $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'EHA-Dashboard/2.0',
    ]);
    $handles[$key] = $ch;
    curl_multi_add_handle($mh, $ch);
}

$active = null;
do { curl_multi_exec($mh, $active); } while ($active);

foreach ($handles as $key => $ch) {
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ms   = (int)(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
    $body = curl_multi_getcontent($ch);
    $results[$key] = ['code' => $code, 'ms' => $ms, 'body' => (string)$body];
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);

$homepage_ok = ($results['homepage']['code'] ?? 0) === 200;
$api_ok      = in_array($results['api']['code'] ?? 0, [200, 204], true);
$catalogo_ok = ($results['catalogo']['code'] ?? 0) < 500;
$carrinho_ok = ($results['carrinho']['code'] ?? 0) < 500
               && (str_contains($results['carrinho']['body'], 'carrinho')
                   || str_contains($results['carrinho']['body'], 'cart'));

$all_ok      = $homepage_ok && $api_ok && $catalogo_ok && $carrinho_ok;
$status      = $all_ok ? 'READY_FOR_PRODUCTION' : 'BLOCKED';

$elapsed     = round(microtime(true) - $t0, 2);
$ts          = date('Y-m-d H:i:s') . ' UTC';

// Lê o log EHA se existir (não crítico)
$log_path    = dirname(__DIR__) . '/automation/eha/reports/eha_events.txt';
$log_lines   = @file($log_path) ?: [];
$recent_log  = array_reverse(array_slice($log_lines, -30));

// Lê last_run.json para detalhes do último run EHA
$last_run_path = dirname(__DIR__) . '/automation/eha/reports/last_run.json';
$last_run      = @json_decode(@file_get_contents($last_run_path) ?: '{}', true) ?: [];

// Lê histórico de runs EHA do run_history.jsonl
$history_path = dirname(__DIR__) . '/automation/eha/reports/run_history.jsonl';
$eha_runs     = [];
if (is_readable($history_path)) {
    foreach (file($history_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if ($r) $eha_runs[] = $r;
    }
}
$eha_runs_recent = array_reverse(array_slice($eha_runs, -20));
$total_runs      = count($eha_runs);
$ok_runs         = count(array_filter($eha_runs, fn($r) => ($r['status'] ?? '') === 'READY_FOR_PRODUCTION'));
$streak          = 0;
foreach (array_reverse($eha_runs) as $r) {
    if (($r['status'] ?? '') === 'READY_FOR_PRODUCTION') $streak++;
    else break;
}
$avg_elapsed = $total_runs > 0
    ? round(array_sum(array_column($eha_runs, 'elapsed_s')) / $total_runs, 2)
    : 0;
$uptime_pct  = $total_runs > 0 ? round($ok_runs / $total_runs * 100, 1) : 0;

$status_color = $all_ok ? '#22c55e' : '#ef4444';

// Lê histórico de runs do GitHub Actions via API pública (sem token)
$gh_runs = [];
$gh_raw  = @file_get_contents(
    'https://api.github.com/repos/fredmourao-ai/site-shopvivaliz/actions/workflows/ci-autonomo-continuo.yml/runs?per_page=10&branch=main',
    false,
    stream_context_create(['http' => ['header' => "User-Agent: EHA-Dashboard/2.0\r\n", 'timeout' => 5]])
);
if ($gh_raw) {
    $gh_data = json_decode($gh_raw, true);
    $gh_runs = $gh_data['workflow_runs'] ?? [];
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
        h2 { font-size: 1rem; font-weight: 600; margin-bottom: .75rem; color: #94a3b8; margin-top: 2rem; }
        .sub { font-size: .85rem; color: #64748b; margin-bottom: 2rem; }
        .badge { display: inline-block; padding: .35rem .9rem; border-radius: 999px; font-weight: 700; font-size: 1rem; color: #fff; background: <?= $status_color ?>; margin-bottom: 1.5rem; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .card { background: #1e293b; border-radius: .75rem; padding: 1rem 1.25rem; }
        .card-label { font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; color: #64748b; margin-bottom: .3rem; }
        .card-value { font-size: 1.15rem; font-weight: 700; }
        .card-sub { font-size: .72rem; color: #475569; margin-top: .2rem; }
        .ok   { color: #22c55e; }
        .fail { color: #ef4444; }
        .warn { color: #f59e0b; }
        table { width: 100%; border-collapse: collapse; font-size: .82rem; margin-bottom: 2rem; }
        th { text-align: left; padding: .5rem .75rem; color: #64748b; font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; border-bottom: 1px solid #1e293b; }
        td { padding: .45rem .75rem; border-bottom: 1px solid #0f172a; }
        tr:hover td { background: #1e293b; }
        .log { background: #0f172a; border: 1px solid #1e293b; border-radius: .5rem; padding: 1rem; font-family: monospace; font-size: .78rem; max-height: 300px; overflow-y: auto; white-space: pre-wrap; color: #64748b; }
        .log .hi { color: #f8fafc; }
        a { color: #38bdf8; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .sparkline { display: flex; gap: 3px; align-items: flex-end; height: 28px; margin: .5rem 0 1.25rem; }
        .spark-bar { width: 10px; border-radius: 2px; min-height: 4px; }
        .spark-ok   { background: #22c55e; }
        .spark-fail { background: #ef4444; }
        .stat-row { display: flex; gap: 2rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
        .stat { font-size: .82rem; color: #94a3b8; }
        .stat strong { color: #f1f5f9; font-size: 1rem; }
    </style>
</head>
<body>
    <h1>EHA — CI Autônomo Contínuo</h1>
    <p class="sub">Health check ao vivo &nbsp;·&nbsp; <?= $ts ?> &nbsp;·&nbsp; <?= $elapsed ?>s &nbsp;·&nbsp; <a href="<?= htmlspecialchars($base) ?>"><?= htmlspecialchars($base) ?></a></p>

    <div class="badge"><?= htmlspecialchars($status) ?></div>

    <div class="grid">
        <div class="card">
            <div class="card-label">Homepage</div>
            <div class="card-value <?= $homepage_ok ? 'ok' : 'fail' ?>"><?= $homepage_ok ? 'OK' : 'FALHOU' ?></div>
            <div class="card-sub">HTTP <?= $results['homepage']['code'] ?> · <?= $results['homepage']['ms'] ?>ms</div>
        </div>
        <div class="card">
            <div class="card-label">API Health</div>
            <div class="card-value <?= $api_ok ? 'ok' : 'fail' ?>"><?= $api_ok ? 'OK' : 'FALHOU' ?></div>
            <div class="card-sub">HTTP <?= $results['api']['code'] ?> · <?= $results['api']['ms'] ?>ms</div>
        </div>
        <div class="card">
            <div class="card-label">Catálogo</div>
            <div class="card-value <?= $catalogo_ok ? 'ok' : 'fail' ?>"><?= $catalogo_ok ? 'OK' : 'FALHOU' ?></div>
            <div class="card-sub">HTTP <?= $results['catalogo']['code'] ?> · <?= $results['catalogo']['ms'] ?>ms</div>
        </div>
        <div class="card">
            <div class="card-label">Carrinho</div>
            <div class="card-value <?= $carrinho_ok ? 'ok' : 'fail' ?>"><?= $carrinho_ok ? 'OK' : 'FALHOU' ?></div>
            <div class="card-sub">HTTP <?= $results['carrinho']['code'] ?> · <?= $results['carrinho']['ms'] ?>ms</div>
        </div>
        <div class="card">
            <div class="card-label">Status geral</div>
            <div class="card-value <?= $all_ok ? 'ok' : 'fail' ?>"><?= $all_ok ? 'HEALTHY' : 'DEGRADED' ?></div>
            <div class="card-sub">Verificado agora</div>
        </div>
    </div>

    <?php if (!empty($last_run)): ?>
    <h2>Último Run EHA — #<?= htmlspecialchars((string)($last_run['run_id'] ?? '?')) ?></h2>
    <div class="grid" style="margin-bottom:1.5rem">
        <div class="card">
            <div class="card-label">Decisão</div>
            <div class="card-value <?= ($last_run['action'] ?? '') === 'DEPLOY_OK' ? 'ok' : (($last_run['action'] ?? '') === 'ROLLBACK' ? 'fail' : 'warn') ?>">
                <?= htmlspecialchars($last_run['action'] ?? '—') ?>
            </div>
            <div class="card-sub"><?= htmlspecialchars($last_run['elapsed_s'] ?? '?') ?>s elapsed</div>
        </div>
        <div class="card">
            <div class="card-label">Validação</div>
            <div class="card-value <?= ($last_run['validation']['status'] ?? '') === 'READY_FOR_PRODUCTION' ? 'ok' : 'fail' ?>">
                <?= htmlspecialchars($last_run['validation']['status'] ?? '—') ?>
            </div>
            <div class="card-sub"><?= htmlspecialchars(substr($last_run['metrics']['timestamp'] ?? '', 0, 16)) ?> UTC</div>
        </div>
        <div class="card">
            <div class="card-label">E2E Tests</div>
            <div class="card-value <?= ($last_run['metrics']['e2e_failed'] ?? false) ? 'warn' : 'ok' ?>">
                <?= ($last_run['metrics']['e2e_failed'] ?? false) ? 'FALHOU' : 'PASSOU' ?>
            </div>
            <div class="card-sub">Checkout: <?= ($last_run['metrics']['checkout_ok'] ?? false) ? '✓' : '✗' ?></div>
        </div>
        <div class="card">
            <div class="card-label">Risco (Loop)</div>
            <div class="card-value <?= ($last_run['loop']['risk'] ?? 'LOW') === 'LOW' ? 'ok' : (($last_run['loop']['risk'] ?? '') === 'HIGH' ? 'fail' : 'warn') ?>">
                <?= htmlspecialchars($last_run['loop']['risk'] ?? '—') ?>
            </div>
            <div class="card-sub"><?= htmlspecialchars($last_run['loop']['issue'] ?? 'none') ?></div>
        </div>
    </div>
    <?php endif; ?>

    <?php
    $e2e_details = $last_run['e2e_details'] ?? [];
    $e2e_total   = (int)($e2e_details['total'] ?? 0);
    if (!empty($last_run) && !empty($last_run['metrics']['e2e_failed']) && $e2e_total > 0):
    ?>
    <h2>Detalhes E2E — Playwright</h2>
    <div class="grid" style="margin-bottom:1rem">
        <div class="card">
            <div class="card-label">Total de testes</div>
            <div class="card-value"><?= $e2e_total ?></div>
        </div>
        <div class="card">
            <div class="card-label">Passou</div>
            <div class="card-value ok"><?= (int)($e2e_details['passed'] ?? 0) ?></div>
        </div>
        <div class="card">
            <div class="card-label">Falhou</div>
            <div class="card-value fail"><?= (int)($e2e_details['failed'] ?? 0) ?></div>
        </div>
        <?php if (!empty($e2e_details['flaky'])): ?>
        <div class="card">
            <div class="card-label">Flaky</div>
            <div class="card-value warn"><?= (int)$e2e_details['flaky'] ?></div>
        </div>
        <?php endif; ?>
    </div>
    <?php if (!empty($e2e_details['failed_tests'])): ?>
    <table style="margin-bottom:2rem">
        <thead>
            <tr><th>Suite</th><th>Teste</th><th>Erro</th></tr>
        </thead>
        <tbody>
        <?php foreach ($e2e_details['failed_tests'] as $ft): ?>
            <tr>
                <td style="color:#94a3b8;font-size:.75rem"><?= htmlspecialchars($ft['suite'] ?? '') ?></td>
                <td class="fail" style="font-weight:600"><?= htmlspecialchars($ft['test'] ?? '') ?></td>
                <td style="font-family:monospace;font-size:.72rem;color:#f59e0b;max-width:400px;word-break:break-word"><?= htmlspecialchars($ft['error'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($eha_runs_recent)): ?>
    <h2>Histórico EHA — últimos <?= count($eha_runs_recent) ?> runs</h2>
    <div class="stat-row">
        <div class="stat">Streak OK <strong class="ok"><?= $streak ?></strong></div>
        <div class="stat">Uptime <strong><?= $uptime_pct ?>%</strong></div>
        <div class="stat">Runs totais <strong><?= $total_runs ?></strong></div>
        <div class="stat">Média elapsed <strong><?= $avg_elapsed ?>s</strong></div>
    </div>
    <div class="sparkline">
    <?php
    $max_e = max(array_column($eha_runs_recent, 'elapsed_s') ?: [1]);
    foreach ($eha_runs_recent as $r):
        $ok  = ($r['status'] ?? '') === 'READY_FOR_PRODUCTION';
        $h   = max(4, (int)round(($r['elapsed_s'] / $max_e) * 28));
        $cls = $ok ? 'spark-ok' : 'spark-fail';
        $tip = '#' . $r['run_id'] . ' · ' . substr($r['ts'] ?? '', 0, 16) . ' · ' . $r['elapsed_s'] . 's';
    ?>
        <div class="spark-bar <?= $cls ?>" style="height:<?= $h ?>px" title="<?= htmlspecialchars($tip) ?>"></div>
    <?php endforeach; ?>
    </div>
    <table>
        <thead>
            <tr><th>Run</th><th>Status</th><th>Ação</th><th>Elapsed</th><th>Checkout</th><th>API</th><th>DB</th><th>E2E</th><th>Erros</th><th>Timestamp (UTC)</th></tr>
        </thead>
        <tbody>
        <?php foreach ($eha_runs_recent as $r):
            $ok = ($r['status'] ?? '') === 'READY_FOR_PRODUCTION';
            $act = $r['action'] ?? '—';
            $act_color = match($act) {
                'DEPLOY_OK'  => '#22c55e',
                'CREATE_PR'  => '#f59e0b',
                'AUTO_FIX'   => '#38bdf8',
                'ROLLBACK'   => '#ef4444',
                default      => '#64748b',
            };
        ?>
            <tr>
                <td>#<?= htmlspecialchars((string)($r['run_id'] ?? '?')) ?></td>
                <td><span class="<?= $ok ? 'ok' : 'fail' ?>"><?= $ok ? '✓ OK' : '✗ BLOCKED' ?></span></td>
                <td><span style="color:<?= $act_color ?>;font-weight:600;font-size:.78rem"><?= htmlspecialchars($act) ?></span></td>
                <td style="color:#94a3b8"><?= number_format((float)($r['elapsed_s'] ?? 0), 2) ?>s</td>
                <td class="<?= ($r['checkout_ok'] ?? false) ? 'ok' : 'fail' ?>"><?= ($r['checkout_ok'] ?? false) ? '✓' : '✗' ?></td>
                <td class="<?= ($r['api_ok'] ?? false) ? 'ok' : 'fail' ?>"><?= ($r['api_ok'] ?? false) ? '✓' : '✗' ?></td>
                <td class="<?= ($r['db_ok'] ?? false) ? 'ok' : 'fail' ?>"><?= ($r['db_ok'] ?? false) ? '✓' : '✗' ?></td>
                <td class="<?= !($r['e2e_failed'] ?? false) ? 'ok' : 'warn' ?>"><?= !($r['e2e_failed'] ?? false) ? '✓' : '!' ?></td>
                <td style="color:<?= ($r['error_count'] ?? 0) > 0 ? '#f59e0b' : '#475569' ?>"><?= (int)($r['error_count'] ?? 0) ?></td>
                <td style="font-size:.75rem;color:#64748b"><?= htmlspecialchars(substr($r['ts'] ?? '', 0, 16)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (!empty($gh_runs)): ?>
    <h2>Últimos runs — CI Autônomo Contínuo</h2>
    <table>
        <thead>
            <tr><th>Run</th><th>Status</th><th>Conclusão</th><th>Disparado em (UTC)</th><th>Link</th></tr>
        </thead>
        <tbody>
        <?php foreach (array_slice($gh_runs, 0, 10) as $run):
            $conc = $run['conclusion'] ?? 'in_progress';
            $color = match($conc) {
                'success'   => '#22c55e',
                'failure'   => '#ef4444',
                'cancelled' => '#94a3b8',
                default     => '#f59e0b',
            };
        ?>
            <tr>
                <td>#<?= (int)$run['run_number'] ?></td>
                <td><?= htmlspecialchars($run['status'] ?? '—') ?></td>
                <td><span style="color:<?= $color ?>;font-weight:600"><?= htmlspecialchars($conc) ?></span></td>
                <td style="font-size:.75rem;color:#64748b"><?= htmlspecialchars(substr($run['created_at'] ?? '', 0, 19)) ?></td>
                <td><a href="<?= htmlspecialchars($run['html_url'] ?? '#') ?>" target="_blank">ver</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (!empty($recent_log)): ?>
    <h2>Log EHA (servidor)</h2>
    <div class="log"><?php
        foreach ($recent_log as $line) {
            $safe = htmlspecialchars(rtrim($line));
            if (str_contains($line, 'DECISION') || str_contains($line, 'VALIDATION') || str_contains($line, 'ROLLBACK')) {
                echo "<span class=\"hi\">$safe</span>\n";
            } else {
                echo "$safe\n";
            }
        }
    ?></div>
    <?php else: ?>
    <h2>Log EHA</h2>
    <div class="log" style="color:#475569">Nenhum log EHA disponível ainda — será populado a partir do próximo run do CI.</div>
    <?php endif; ?>
</body>
</html>
