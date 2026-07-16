<?php
/**
 * Orchestrator Director — ShopVivaliz
 *
 * Analisa o relatório do autonomous-report.php e decide quais tarefas enfileirar.
 * Funciona como o "Diretor Geral" que interpreta o estado e age de forma autônoma.
 *
 * GET /api/orchestrator/director.php?secret=CRON_SECRET
 * GET /api/orchestrator/director.php?secret=CRON_SECRET&dry_run=1  (apenas analisa, não enfileira)
 */

declare(strict_types=1);

// ── Bootstrap ─────────────────────────────────────────────────────────────────
(static function () {
    $f = dirname(__DIR__, 2) . '/.env';
    if (!is_file($f)) return;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim(trim($v), '"\'');
        if ($k !== '' && getenv($k) === false) putenv("$k=$v");
    }
})();

require_once __DIR__ . '/queue.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Helpers ───────────────────────────────────────────────────────────────────
function odir_env(string $key): string { return (string)(getenv($key) ?: ''); }

function odir_log(string $msg): void {
    $dir = dirname(__DIR__, 2) . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $line = '[' . date('c') . '] [director] ' . $msg . "\n";
    file_put_contents($dir . '/orchestrator.log', $line, FILE_APPEND | LOCK_EX);
}

function odir_http_get(string $url, int $timeout = 15): array {
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'timeout'       => $timeout,
        'ignore_errors' => true,
        'header'        => 'User-Agent: ShopVivaliz-Director/1.0',
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return ['ok' => false, 'error' => 'unreachable'];
    $data = json_decode($body, true);
    return is_array($data) ? $data : ['ok' => false, 'raw' => substr($body, 0, 500)];
}

// ── Autenticação ──────────────────────────────────────────────────────────────
$cronSecret = odir_env('CRON_SECRET');
if ($cronSecret !== '' && ($_GET['secret'] ?? '') !== $cronSecret) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acesso negado.']);
    exit;
}

$dryRun  = (bool)($_GET['dry_run'] ?? false);
$baseUrl = rtrim(odir_env('SITE_URL') ?: 'https://dev.shopvivaliz.com.br', '/');

// ── Busca relatório atual ─────────────────────────────────────────────────────
odir_log('início análise' . ($dryRun ? ' (dry_run)' : ''));

$reportUrl = $baseUrl . '/api/agent/autonomous-report.php';
$report    = odir_http_get($reportUrl);

if (!is_array($report) || isset($report['error'])) {
    odir_log('HTTP request failed. Falling back to local file.');
    $localReportFile = dirname(__DIR__, 2) . '/logs/autonomous-cycle-report.json';
    if (is_file($localReportFile)) {
        $localData = json_decode((string)file_get_contents($localReportFile), true);
        if (is_array($localData)) {
            $report = $localData;
            odir_log('Successfully loaded report fallback from local file.');
        }
    }
}

if (!is_array($report) || isset($report['error'])) {
    $err = ['ok' => false, 'error' => 'Falha ao obter relatório', 'detail' => $report];
    odir_log('erro: falha ao obter relatório');
    echo json_encode($err, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Regras de Decisão ─────────────────────────────────────────────────────────
$decisions = [];

$catalog      = $report['catalog']      ?? [];
$integrations = $report['integrations'] ?? [];
$ranking      = $report['ranking']      ?? [];
$demand       = $report['demand']       ?? [];
$roi          = $report['roi']          ?? [];

// Regra 1: Muitos produtos com preço zero → price-sync-check urgente
$zeroPrice = (int)($catalog['zero_price'] ?? 0);
$total     = (int)($catalog['total']      ?? 0);
if ($zeroPrice > 0 && $total > 0 && ($zeroPrice / max($total, 1)) > 0.1) {
    $decisions[] = [
        'trigger'   => 'zero_price',
        'reason'    => "Produtos com preço zero: $zeroPrice de $total (acima de 10%)",
        'task_type' => 'price-sync-check',
        'priority'  => 'high',
        'data'      => ['endpoint' => '/api/agent/cron-dispatcher.php?task=status', 'zero_price' => $zeroPrice],
    ];
}

// Regra 2: Catálogo desatualizado (> 2h) → watchdog
$fileAgeS = (int)($catalog['file_age_s'] ?? 0);
if ($fileAgeS > 7200) {
    $decisions[] = [
        'trigger'   => 'stale_catalog',
        'reason'    => "Catálogo com {$fileAgeS}s de idade (mais de 2h)",
        'task_type' => 'watchdog',
        'priority'  => 'high',
        'data'      => ['endpoint' => '/api/agent/autonomous-watchdog.php', 'stale_age_s' => $fileAgeS],
    ];
}

// Regra 3: Muitos produtos sem imagem → watchdog
$noImage = (int)($catalog['no_image'] ?? 0);
if ($noImage > 10) {
    $decisions[] = [
        'trigger'   => 'no_image',
        'reason'    => "Produtos sem imagem: $noImage",
        'task_type' => 'watchdog',
        'priority'  => 'normal',
        'data'      => ['endpoint' => '/api/agent/autonomous-watchdog.php', 'no_image' => $noImage],
    ];
}

// Regra 4: Ranking ausente ou desatualizado (> 24h) → report
$rankingAge = (int)($ranking['file_age_s'] ?? PHP_INT_MAX);
if (!($ranking['available'] ?? false) || $rankingAge > 86400) {
    $decisions[] = [
        'trigger'   => 'stale_ranking',
        'reason'    => !($ranking['available'] ?? false)
            ? 'Ranking de produtos ausente'
            : "Ranking desatualizado: {$rankingAge}s",
        'task_type' => 'report',
        'priority'  => 'normal',
        'data'      => ['endpoint' => '/api/agent/autonomous-report.php'],
    ];
}

// Regra 5: ML OAuth desconectado → watchdog para detectar
if (!($integrations['ml_oauth_connected'] ?? false)) {
    $decisions[] = [
        'trigger'   => 'ml_disconnected',
        'reason'    => 'MercadoLivre OAuth não está conectado',
        'task_type' => 'watchdog',
        'priority'  => 'high',
        'data'      => ['endpoint' => '/api/agent/autonomous-watchdog.php', 'ml_oauth' => false],
    ];
}

// Regra 6: Token ML muito antigo (> 6h) → watchdog
$mlTokenAge = (int)($integrations['ml_token_age_s'] ?? 0);
if ($mlTokenAge > 21600 && ($integrations['ml_oauth_connected'] ?? false)) {
    $decisions[] = [
        'trigger'   => 'ml_token_stale',
        'reason'    => "Token ML com {$mlTokenAge}s (mais de 6h)",
        'task_type' => 'watchdog',
        'priority'  => 'normal',
        'data'      => ['endpoint' => '/api/agent/autonomous-watchdog.php', 'ml_token_age_s' => $mlTokenAge],
    ];
}

// Regra 7: ROI apontando oportunidade de venda → sales-flow
$topOpportunity = $roi['top_opportunities'][0] ?? $roi['priorities'][0] ?? null;
if (is_array($topOpportunity) && !empty($topOpportunity['sku'])) {
    $decisions[] = [
        'trigger'   => 'roi_top_opportunity',
        'reason'    => sprintf(
            'ROI prioriza %s em %s (%s)',
            (string)($topOpportunity['action'] ?? 'melhoria'),
            (string)($topOpportunity['sku'] ?? 'sku-unknown'),
            (string)($topOpportunity['impact'] ?? 'medio')
        ),
        'task_type' => 'sales-flow',
        'priority'  => 'high',
        'data'      => [
            'endpoint' => '/api/agent/autonomous-report.php',
            'sku' => $topOpportunity['sku'],
            'target' => $topOpportunity['target'] ?? null,
            'action' => $topOpportunity['action'] ?? null,
            'impact' => $topOpportunity['impact'] ?? null,
            'priority_score' => $topOpportunity['priority_score'] ?? null,
            'channel' => $topOpportunity['channel'] ?? null,
        ],
    ];
}

// ── Executa decisões (se não for dry_run) ────────────────────────────────────
$enacted = [];

if (!$dryRun) {
    foreach ($decisions as &$dec) {
        $id = queue_push($dec['task_type'], $dec['priority'], $dec['data']);
        $dec['enqueued'] = $id !== false;
        $dec['task_id']  = $id ?: null;

        if ($id !== false) {
            odir_log("enfileirou: type={$dec['task_type']} trigger={$dec['trigger']} id=$id");
            $enacted[] = $dec;
        }
    }
    unset($dec);
}

odir_log(sprintf(
    'fim: decisões=%d enfileiradas=%d dry_run=%s',
    count($decisions),
    count($enacted),
    $dryRun ? 'true' : 'false'
));

// ── Resposta ──────────────────────────────────────────────────────────────────
echo json_encode([
    'ok'           => true,
    'generated_at' => date('c'),
    'dry_run'      => $dryRun,
    'report_snapshot' => [
        'catalog_total'     => $total,
        'zero_price'        => $zeroPrice,
        'no_image'          => $noImage,
        'catalog_age_s'     => $fileAgeS,
        'ml_connected'      => $integrations['ml_oauth_connected'] ?? false,
        'ranking_available' => $ranking['available'] ?? false,
    ],
    'decisions'    => $decisions,
    'enacted_count'=> count($enacted),
    'queue'        => queue_status(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
