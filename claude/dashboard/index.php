<?php
/**
 * EHA Status Dashboard — shopvivaliz.com.br/claude
 * Health check ao vivo + status dos providers de IA.
 */
header('Content-Type: text/html; charset=utf-8');

$base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
      . '://' . ($_SERVER['HTTP_HOST'] ?? 'shopvivaliz.com.br');

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

// Health checks em paralelo
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

// --- Status dos Providers de IA (via squad-chat health) ---
$squad_health_url = $base . '/api/agent/squad-chat.php?health=1';
$squad_health_raw = http_check($squad_health_url, 6);
$squad_health     = @json_decode($squad_health_raw['body'], true) ?: [];
$providers        = $squad_health['providers'] ?? [];

// Mapeamento amigável de nomes
$provider_labels = [
    'anthropic' => ['nome' => 'Anthropic (Claude)',  'emoji' => '🧠'],
    'openai'    => ['nome' => 'OpenAI (GPT)',        'emoji' => '💻'],
    'gemini'    => ['nome' => 'Google (Gemini)',     'emoji' => '✨'],
];

$ai_any_down  = false;
$ai_all_down  = !empty($providers) && count($providers) > 0;
foreach ($providers as $key => $info) {
    $ok = (bool)($info['configured'] ?? false);
    if (!$ok) $ai_any_down = true;
    if ($ok)  $ai_all_down = false;
}

// Leitura dos arquivos de report (gracioso se ausentes)
$log_path    = dirname(__DIR__, 2) . '/automation/eha/reports/eha_events.txt';
$log_lines   = @file($log_path) ?: [];
$recent_log  = array_reverse(array_slice($log_lines, -30));

$last_run_path = dirname(__DIR__, 2) . '/automation/eha/reports/last_run.json';
$last_run      = @json_decode(@file_get_contents($last_run_path) ?: '{}', true) ?: [];

$last_ci_path = dirname(__DIR__, 2) . '/automation/eha/reports/last_ci_run.json';
$last_ci      = @json_decode(@file_get_contents($last_ci_path) ?: '{}', true) ?: [];

// Medusa migration status
$medusa_path   = dirname(__DIR__, 2) . '/automation/eha/reports/medusa-last-run.json';
$medusa_report = @json_decode(@file_get_contents($medusa_path) ?: '{}', true) ?: [];
$medusa_ts_raw = $medusa_report['state']['timestamp'] ?? '';
$medusa_ts_unix = $medusa_ts_raw ? (int)strtotime($medusa_ts_raw) : 0;
$medusa_hours_ago = $medusa_ts_unix > 0 ? (int)round((time() - $medusa_ts_unix) / 3600) : -1;
$medusa_stuck = $medusa_hours_ago > 4;  // stuck if same step for more than 4h
$medusa_step_key = $medusa_report['next_step_key'] ?? '';
$medusa_applied  = $medusa_report['applied_actions'] ?? [];

$history_path = dirname(__DIR__, 2) . '/automation/eha/reports/run_history.jsonl';
$eha_runs     = [];
if (is_readable($history_path)) {
    foreach (file($history_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if ($r) $eha_runs[] = $r;
    }
}
$eha_runs_recent = array_reverse(array_slice($eha_runs, -40));
$total_runs      = count($eha_runs);
$ok_runs         = count(array_filter($eha_runs, fn($r) => ($r['status'] ?? '') === 'READY_FOR_PRODUCTION'));
$e2e_ok_runs     = count(array_filter($eha_runs, fn($r) => !($r['e2e_failed'] ?? false)));
$streak          = 0;
foreach (array_reverse($eha_runs) as $r) {
    if (($r['status'] ?? '') === 'READY_FOR_PRODUCTION') $streak++;
    else break;
}
$e2e_streak_ok   = 0;
foreach (array_reverse($eha_runs) as $r) {
    if (!($r['e2e_failed'] ?? false)) $e2e_streak_ok++;
    else break;
}
$avg_elapsed = $total_runs > 0
    ? round(array_sum(array_column($eha_runs, 'elapsed_s')) / $total_runs, 2)
    : 0;
$uptime_pct  = $total_runs > 0 ? round($ok_runs / $total_runs * 100, 1) : 0;
$e2e_pct     = $total_runs > 0 ? round($e2e_ok_runs / $total_runs * 100, 1) : 0;

$e2e_consecutive = (int)($last_run['e2e_consecutive'] ?? 0);
$e2e_alert       = $e2e_consecutive >= 10;

$ci_ts_raw   = $last_ci['timestamp'] ?? '';
$ci_ts_unix  = $ci_ts_raw ? (int)strtotime($ci_ts_raw) : 0;
$ci_mins_ago = $ci_ts_unix > 0 ? (int)round((time() - $ci_ts_unix) / 60) : -1;
$ci_stale    = $ci_mins_ago < 0 || $ci_mins_ago > 25;

$status_color = $all_ok ? '#22c55e' : '#ef4444';
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
        .grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .card { background: #1e293b; border-radius: .75rem; padding: 1rem 1.25rem; }
        .card.alert  { border: 1px solid #f59e0b; }
        .card.stale  { border: 1px solid #60a5fa; }
        .card.danger { border: 1px solid #ef4444; background: #1a0a0a; }
        .card-label { font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; color: #64748b; margin-bottom: .3rem; }
        .card-value { font-size: 1.15rem; font-weight: 700; }
        .card-sub { font-size: .72rem; color: #475569; margin-top: .2rem; }
        .ok   { color: #22c55e; }
        .fail { color: #ef4444; }
        .warn { color: #f59e0b; }
        .info { color: #60a5fa; }
        .alert-banner { background: #7c2d12; border: 1px solid #f59e0b; border-radius: .75rem; padding: 1rem 1.25rem; margin-bottom: 1.5rem; color: #fef3c7; font-size: .9rem; }
        .alert-banner strong { color: #fbbf24; }
        .danger-banner { background: #1f0505; border: 1px solid #ef4444; border-radius: .75rem; padding: 1rem 1.25rem; margin-bottom: 1.5rem; color: #fecaca; font-size: .9rem; }
        .danger-banner strong { color: #f87171; }
        .info-banner { background: #172554; border: 1px solid #60a5fa; border-radius: .75rem; padding: 1rem 1.25rem; margin-bottom: 1.5rem; color: #bfdbfe; font-size: .9rem; }
        .info-banner strong { color: #93c5fd; }
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
        .spark-e2e  { background: #f59e0b; }
        .stat-row { display: flex; gap: 2rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
        .stat { font-size: .82rem; color: #94a3b8; }
        .stat strong { color: #f1f5f9; font-size: 1rem; }
        .provider-card { background: #1e293b; border-radius: .75rem; padding: 1.25rem; display: flex; flex-direction: column; gap: .5rem; }
        .provider-card.ok-provider  { border-left: 4px solid #22c55e; }
        .provider-card.fail-provider { border-left: 4px solid #ef4444; background: #1a0d0d; }
        .provider-name { font-size: 1rem; font-weight: 700; }
        .provider-model { font-size: .78rem; color: #64748b; }
        .provider-status { font-size: .82rem; font-weight: 600; margin-top: .25rem; }
    </style>
</head>
<body>
    <h1>EHA — CI Autônomo Contínuo</h1>
    <p class="sub">Health check ao vivo &nbsp;&middot;&nbsp; <?= $ts ?> &nbsp;&middot;&nbsp; <?= $elapsed ?>s &nbsp;&middot;&nbsp; <a href="<?= htmlspecialchars($base) ?>"><?= htmlspecialchars($base) ?></a></p>

    <div class="badge"><?= htmlspecialchars($status) ?></div>

    <?php if ($ai_all_down && !empty($providers)): ?>
    <div class="danger-banner">
        🚨 <strong>TODOS os providers de IA estão offline.</strong>
        Squad Chat completamente inoperante. Verifique as chaves de API:
        Anthropic (crédito insuficiente), OpenAI (cota excedida), Gemini (chave inválida).
        Acesse <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a>,
        <a href="https://platform.openai.com" target="_blank">platform.openai.com</a> e
        <a href="https://aistudio.google.com" target="_blank">aistudio.google.com</a> para renovar.
    </div>
    <?php elseif ($ai_any_down && !empty($providers)): ?>
    <div class="alert-banner">
        ⚠️ <strong>Um ou mais providers de IA estão offline.</strong>
        Squad Chat com capacidade reduzida. Verifique a seção "Providers de IA" abaixo.
    </div>
    <?php endif; ?>

    <?php if ($e2e_alert): ?>
    <div class="alert-banner">
        ⚠️ <strong>E2E persistentemente falhando:</strong> <?= $e2e_consecutive ?> runs consecutivos com falha.
        O sistema escalou para <strong>CREATE_PR</strong> — verifique a infraestrutura do Playwright / checkout.spec.js.
    </div>
    <?php endif; ?>

    <?php if ($ci_stale): ?>
    <div class="info-banner">
        ⏰ <strong>CI possivelmente parado:</strong>
        <?php if ($ci_mins_ago < 0): ?>
            Nenhum registro de CI encontrado — aguardando primeiro run.
        <?php else: ?>
            Último run foi há <strong><?= $ci_mins_ago ?> minutos</strong>.
            O cron roda a cada 15min — verifique os <a href="https://github.com/fredmourao-ai/site-shopvivaliz/actions/workflows/ci-autonomo-continuo.yml" target="_blank">GitHub Actions →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- SITE HEALTH -->
    <div class="grid">
        <div class="card">
            <div class="card-label">Homepage</div>
            <div class="card-value <?= $homepage_ok ? 'ok' : 'fail' ?>"><?= $homepage_ok ? 'OK' : 'FALHOU' ?></div>
            <div class="card-sub">HTTP <?= $results['homepage']['code'] ?> &middot; <?= $results['homepage']['ms'] ?>ms</div>
        </div>
        <div class="card">
            <div class="card-label">API Health</div>
            <div class="card-value <?= $api_ok ? 'ok' : 'fail' ?>"><?= $api_ok ? 'OK' : 'FALHOU' ?></div>
            <div class="card-sub">HTTP <?= $results['api']['code'] ?> &middot; <?= $results['api']['ms'] ?>ms</div>
        </div>
        <div class="card">
            <div class="card-label">Catálogo</div>
            <div class="card-value <?= $catalogo_ok ? 'ok' : 'fail' ?>"><?= $catalogo_ok ? 'OK' : 'FALHOU' ?></div>
            <div class="card-sub">HTTP <?= $results['catalogo']['code'] ?> &middot; <?= $results['catalogo']['ms'] ?>ms</div>
        </div>
        <div class="card">
            <div class="card-label">Carrinho</div>
            <div class="card-value <?= $carrinho_ok ? 'ok' : 'fail' ?>"><?= $carrinho_ok ? 'OK' : 'FALHOU' ?></div>
            <div class="card-sub">HTTP <?= $results['carrinho']['code'] ?> &middot; <?= $results['carrinho']['ms'] ?>ms</div>
        </div>
        <div class="card">
            <div class="card-label">Status geral</div>
            <div class="card-value <?= $all_ok ? 'ok' : 'fail' ?>"><?= $all_ok ? 'HEALTHY' : 'DEGRADED' ?></div>
            <div class="card-sub">Verificado agora</div>
        </div>
        <div class="card <?= $e2e_alert ? 'alert' : '' ?>">
            <div class="card-label">E2E Streak Falhas</div>
            <div class="card-value <?= $e2e_consecutive === 0 ? 'ok' : ($e2e_alert ? 'fail' : 'warn') ?>">
                <?= $e2e_consecutive ?> runs
            </div>
            <div class="card-sub"><?= $e2e_alert ? '⚠️ Escalando CREATE_PR' : ($e2e_consecutive > 0 ? 'monitorando' : 'E2E estável') ?></div>
        </div>
        <div class="card <?= $ci_stale ? 'stale' : '' ?>">
            <div class="card-label">Último CI Run</div>
            <div class="card-value <?= $ci_stale ? 'info' : 'ok' ?>">
                <?= $ci_mins_ago >= 0 ? $ci_mins_ago . ' min atrás' : 'sem dados' ?>
            </div>
            <div class="card-sub"><?= $ci_stale ? '⏰ verifique o cron' : '✓ CI ativo' ?></div>
        </div>
    </div>

    <!-- PROVIDERS DE IA -->
    <?php if (!empty($providers) || $squad_health_raw['code'] !== 0): ?>
    <h2>Providers de IA — Squad Chat</h2>
    <?php if ($squad_health_raw['code'] === 200 && !empty($providers)): ?>
    <div class="grid-3">
    <?php foreach ($providers as $key => $info):
        $ok      = (bool)($info['configured'] ?? false);
        $label   = $provider_labels[$key] ?? ['nome' => ucfirst($key), 'emoji' => '🤖'];
        $model   = $info['model'] ?? 'n/d';
        $cls_card = $ok ? 'ok-provider' : 'fail-provider';
        $cls_txt  = $ok ? 'ok' : 'fail';
        $status_txt = $ok ? '✓ Online' : '✗ Offline';
        $hint = '';
        if (!$ok) {
            $hint = match($key) {
                'anthropic' => 'crédito insuficiente — recarregar em console.anthropic.com',
                'openai'    => 'cota excedida — verificar em platform.openai.com',
                'gemini'    => 'chave inválida — renovar em aistudio.google.com',
                default     => 'verifique a chave de API',
            };
        }
    ?>
        <div class="provider-card <?= $cls_card ?>">
            <div class="provider-name"><?= $label['emoji'] ?> <?= htmlspecialchars($label['nome']) ?></div>
            <div class="provider-model">Modelo: <?= htmlspecialchars($model) ?></div>
            <div class="provider-status <?= $cls_txt ?>"><?= $status_txt ?></div>
            <?php if ($hint): ?>
            <div style="font-size:.75rem;color:#f87171;margin-top:.2rem"><?= htmlspecialchars($hint) ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="card" style="margin-bottom:1.5rem">
        <div class="card-label">Squad Chat Health</div>
        <div class="card-value warn">Não disponível</div>
        <div class="card-sub">HTTP <?= $squad_health_raw['code'] ?> — endpoint não acessível neste ambiente</div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- ÚLTIMO RUN EHA -->
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

    <!-- HISTÓRICO EHA -->
    <?php if (!empty($eha_runs_recent)): ?>
    <h2>Histórico EHA — últimos <?= count($eha_runs_recent) ?> runs</h2>
    <div class="stat-row">
        <div class="stat">Streak OK <strong class="ok"><?= $streak ?></strong></div>
        <div class="stat">Uptime <strong><?= $uptime_pct ?>%</strong></div>
        <div class="stat">E2E pass rate <strong class="<?= $e2e_pct >= 90 ? 'ok' : ($e2e_pct >= 70 ? 'warn' : 'fail') ?>"><?= $e2e_pct ?>%</strong></div>
        <div class="stat">E2E streak OK <strong class="<?= $e2e_streak_ok >= 20 ? 'ok' : ($e2e_streak_ok >= 5 ? 'warn' : 'fail') ?>"><?= $e2e_streak_ok ?></strong></div>
        <div class="stat">Runs totais <strong><?= $total_runs ?></strong></div>
        <div class="stat">Média elapsed <strong><?= $avg_elapsed ?>s</strong></div>
    </div>
    <div class="sparkline">
    <?php
    $max_e = max(array_column($eha_runs_recent, 'elapsed_s') ?: [1]);
    foreach ($eha_runs_recent as $r):
        $ok      = ($r['status'] ?? '') === 'READY_FOR_PRODUCTION';
        $e2e_ok  = !($r['e2e_failed'] ?? false);
        $h       = max(4, (int)round(($r['elapsed_s'] / $max_e) * 28));
        $cls     = !$ok ? 'spark-fail' : ($e2e_ok ? 'spark-ok' : 'spark-e2e');
        $tip     = '#' . $r['run_id'] . ' · ' . substr($r['ts'] ?? '', 0, 16) . ' · ' . $r['elapsed_s'] . 's'
                 . ($ok ? ' · OK' : ' · BLOCKED')
                 . ($e2e_ok ? '' : ' · E2E FAIL');
    ?>
        <div class="spark-bar <?= $cls ?>" style="height:<?= $h ?>px" title="<?= htmlspecialchars($tip) ?>"></div>
    <?php endforeach; ?>
    </div>
    <div style="font-size:.7rem;color:#64748b;margin-bottom:1rem;display:flex;gap:1rem">
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#22c55e;vertical-align:middle"></span> OK</span>
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#f59e0b;vertical-align:middle"></span> OK mas E2E falhou</span>
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#ef4444;vertical-align:middle"></span> BLOCKED</span>
    </div>
    <table>
        <thead>
            <tr><th>Run</th><th>Status</th><th>Ação</th><th>Elapsed</th><th>Checkout</th><th>API</th><th>DB</th><th>E2E</th><th>E2E Streak</th><th>Erros</th><th>Timestamp (UTC)</th></tr>
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
            $e2e_streak = (int)($r['e2e_consecutive'] ?? 0);
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
                <td class="<?= $e2e_streak >= 10 ? 'fail' : ($e2e_streak > 0 ? 'warn' : 'ok') ?>"><?= $e2e_streak > 0 ? $e2e_streak . 'x' : '—' ?></td>
                <td style="color:<?= ($r['error_count'] ?? 0) > 0 ? '#f59e0b' : '#475569' ?>"><?= (int)($r['error_count'] ?? 0) ?></td>
                <td style="font-size:.75rem;color:#64748b"><?= htmlspecialchars(substr($r['ts'] ?? '', 0, 16)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- ÚLTIMO CI RUN -->
    <?php if (!empty($last_ci)): ?>
    <h2>Último CI Run — #<?= (int)($last_ci['run_number'] ?? 0) ?></h2>
    <div class="grid" style="margin-bottom:1.5rem">
        <div class="card">
            <div class="card-label">Run</div>
            <div class="card-value">#<?= (int)($last_ci['run_number'] ?? 0) ?></div>
            <div class="card-sub"><?= htmlspecialchars($last_ci['event'] ?? '—') ?></div>
        </div>
        <div class="card">
            <div class="card-label">EHA Status</div>
            <div class="card-value <?= ($last_ci['eha_status'] ?? '') === 'READY_FOR_PRODUCTION' ? 'ok' : (($last_ci['eha_status'] ?? '') === 'BLOCKED' ? 'fail' : 'warn') ?>">
                <?= htmlspecialchars($last_ci['eha_status'] ?? '—') ?>
            </div>
            <div class="card-sub"><?= htmlspecialchars(substr($last_ci['timestamp'] ?? '', 0, 16)) ?> UTC</div>
        </div>
        <div class="card">
            <div class="card-label">Commit SHA</div>
            <div class="card-value" style="font-family:monospace;font-size:.9rem"><?= htmlspecialchars(substr($last_ci['sha'] ?? '', 0, 8)) ?></div>
            <div class="card-sub">main branch</div>
        </div>
        <div class="card">
            <div class="card-label">Link</div>
            <div class="card-value" style="font-size:.9rem"><a href="<?= htmlspecialchars($last_ci['url'] ?? '#') ?>" target="_blank">Ver no GitHub →</a></div>
            <div class="card-sub">Actions log completo</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- MIGRAÇÃO MEDUSA -->
    <?php if (!empty($medusa_report)): ?>
    <h2>Migração Medusa</h2>
    <?php if ($medusa_stuck): ?>
    <div class="alert-banner">
        ⚠️ <strong>Migração parada há <?= $medusa_hours_ago ?>h:</strong>
        Passo atual <code style="background:#0f172a;padding:2px 6px;border-radius:3px"><?= htmlspecialchars($medusa_step_key) ?></code>
        requer intervenção manual — inicie o backend Medusa e gere a publishable key via admin API.
    </div>
    <?php endif; ?>
    <div class="grid-3" style="margin-bottom:1.5rem">
        <div class="card <?= ($medusa_report['status'] ?? '') === 'completed' ? '' : ($medusa_stuck ? 'alert' : '') ?>">
            <div class="card-label">Status</div>
            <div class="card-value <?= ($medusa_report['status'] ?? '') === 'completed' ? 'ok' : ($medusa_stuck ? 'warn' : 'info') ?>">
                <?= htmlspecialchars(strtoupper($medusa_report['status'] ?? '—')) ?>
            </div>
            <div class="card-sub">
                <?= $medusa_hours_ago >= 0 ? "atualizado há {$medusa_hours_ago}h" : 'sem timestamp' ?>
            </div>
        </div>
        <div class="card">
            <div class="card-label">Próximo passo</div>
            <div class="card-value warn" style="font-size:.9rem;word-break:break-all">
                <?= htmlspecialchars($medusa_report['next_step_title'] ?? '—') ?>
            </div>
            <div class="card-sub" style="font-size:.7rem"><?= htmlspecialchars($medusa_step_key) ?></div>
        </div>
        <div class="card">
            <div class="card-label">Passos concluídos</div>
            <div class="card-value ok"><?= count($medusa_applied) ?></div>
            <div class="card-sub"><?= htmlspecialchars(implode(', ', $medusa_applied) ?: 'nenhum') ?></div>
        </div>
        <div class="card">
            <div class="card-label">Backend</div>
            <div class="card-value <?= ($medusa_report['state']['backend_exists'] ?? false) ? 'ok' : 'fail' ?>">
                <?= ($medusa_report['state']['backend_exists'] ?? false) ? '✓ Existe' : '✗ Ausente' ?>
            </div>
            <div class="card-sub">medusa/apps/backend</div>
        </div>
        <div class="card">
            <div class="card-label">Storefront</div>
            <div class="card-value <?= ($medusa_report['state']['storefront_exists'] ?? false) ? 'ok' : 'fail' ?>">
                <?= ($medusa_report['state']['storefront_exists'] ?? false) ? '✓ Existe' : '✗ Ausente' ?>
            </div>
            <div class="card-sub">medusa/apps/storefront</div>
        </div>
        <div class="card">
            <div class="card-label">Publishable Key</div>
            <div class="card-value <?= ($medusa_report['state']['storefront_publishable_key_present'] ?? false) ? 'ok' : 'fail' ?>">
                <?= ($medusa_report['state']['storefront_publishable_key_present'] ?? false) ? '✓ Configurada' : '✗ Faltando' ?>
            </div>
            <div class="card-sub">necessária para storefront</div>
        </div>
    </div>
    <?php if (!empty($medusa_report['next_step_description'])): ?>
    <div class="card" style="margin-bottom:1.5rem;border-left:4px solid #f59e0b">
        <div class="card-label">O que fazer agora</div>
        <div style="font-size:.88rem;color:#cbd5e1;margin-top:.5rem;line-height:1.5">
            <?= htmlspecialchars($medusa_report['next_step_description']) ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- LOG EHA -->
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
