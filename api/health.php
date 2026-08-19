<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Rodada 4 (2026-08-19): este endpoint e publico e sem autenticacao (usado
// por monitores de uptime externos, entao nao pode simplesmente exigir
// SHOPVIVALIZ_AGENT_KEY e devolver 401/503 -- isso quebraria o monitoramento
// hoje). O que era publico por padrao era o problema: versao exata do PHP,
// software do servidor, % de disco usado, quais integracoes tem secret
// configurado, estado de fila de tarefas. Isso da a um atacante um
// fingerprint completo da infra sem nenhum reconhecimento ativo. Agora o
// payload detalhado so sai com a chave de agente valida (mesmo mecanismo de
// config/require-agent-key.php, mas sem abortar a requisicao); sem a chave,
// a resposta e reduzida a {ok, status, generated_at, health_score_percent}
// -- o suficiente pra qualquer monitor externo. Ver R3-4/R4-4 no relatorio
// das Rodadas 3/4.
require_once __DIR__ . '/../config/require-agent-key.php';
$svHealthDetailed = PHP_SAPI === 'cli';
if (!$svHealthDetailed) {
    $svHealthExpectedKey = '';
    foreach (['SHOPVIVALIZ_AGENT_KEY', 'RUNTIME_AGENT_KEY', 'AUTONOMOUS_AGENT_KEY'] as $svHealthKeyName) {
        $svHealthKeyValue = getenv($svHealthKeyName);
        if (is_string($svHealthKeyValue) && trim($svHealthKeyValue) !== '') {
            $svHealthExpectedKey = trim($svHealthKeyValue);
            break;
        }
    }
    $svHealthDetailed = $svHealthExpectedKey !== '' && hash_equals($svHealthExpectedKey, sv_agent_key_from_request());
}

$root = dirname(__DIR__);

function sv_health_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (float)$value;
    return match ($unit) {
        'g' => (int)($number * 1024 * 1024 * 1024),
        'm' => (int)($number * 1024 * 1024),
        'k' => (int)($number * 1024),
        default => (int)$number,
    };
}

function sv_health_is_writable_dir(string $path): bool
{
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
    return is_dir($path) && is_writable($path);
}

function sv_health_probe_queue(array &$checks, string $root): array
{
    $queueSummary = null;
    if (is_file($root . '/core/queue/queue.php')) {
        try {
            require_once $root . '/core/queue/queue.php';
            if (function_exists('sv_queue_summary')) {
                if (function_exists('sv_queue_reap_stale')) {
                    $reaped = sv_queue_reap_stale(900);
                    $checks['Jobs obsoletos reprocessados'] = $reaped >= 0;
                }
                $queueSummary = sv_queue_summary();
                $checks['Fila com schema reconhecido'] = is_array($queueSummary);
                $checks['Fila persistente ativa'] = is_array($queueSummary);
            } else {
                $checks['Fila com schema reconhecido'] = false;
            }
        } catch (Throwable $e) {
            $checks['Fila com schema reconhecido'] = false;
            $queueSummary = ['error' => $e->getMessage()];
        }
    } else {
        $checks['Fila com schema reconhecido'] = false;
    }

    if (!is_array($queueSummary)) {
        $queueSummary = [];
    }

    // Rodada 9 (2026-08-19): o check antigo somava 4 contadores nao-negativos
    // e comparava com >= 0 -- e sempre true por construcao, nunca detectou
    // nada. "Fila sem backlog travado" so olha 'stale' (job preso em
    // 'running' ha >15min); se NENHUM job chega a rodar (caso do R9-1: fila
    // sem worker consumindo), 'stale' tambem fica sempre 0. Nenhum dos dois
    // olhava pra 'queued'. Troquei por um limiar real. Ver R9-6 no relatorio
    // da Rodada 9 -- e a 4a/5a ocorrencia do padrao "guarda que roda e nao
    // detecta o comportamento que interessa" (depois de R6-4, R6-5, R8-9).
    if (isset($queueSummary['total']) && (int)$queueSummary['total'] > 0) {
        $checks['Fila sem backlog acumulado'] = ((int)($queueSummary['queued'] ?? 0)) < 50;
    }
    if (isset($queueSummary['stale'])) {
        $checks['Fila sem backlog travado'] = ((int)$queueSummary['stale']) === 0;
    }

    return $queueSummary;
}

function sv_health_probe_asset_manifest(array &$checks, string $root): array
{
    $manifestPath = $root . '/public/dist/asset-manifest.json';
    if (!is_file($manifestPath) || !is_readable($manifestPath)) {
        $checks['Manifest de assets presente'] = false;
        return ['ok' => false, 'assets' => 0, 'error' => 'manifest_missing'];
    }

    $decoded = json_decode((string)file_get_contents($manifestPath), true);
    $assets = is_array($decoded['assets'] ?? null) ? $decoded['assets'] : null;
    if ($assets === null) {
        $checks['Manifest de assets presente'] = false;
        return ['ok' => false, 'assets' => 0, 'error' => 'manifest_invalid'];
    }

    $missing = [];
    foreach ($assets as $source => $entry) {
        $file = is_array($entry) ? (string)($entry['file'] ?? '') : '';
        if ($file === '' || $file[0] !== '/') {
            $missing[] = (string)$source;
            continue;
        }

        if (!is_file($root . '/' . ltrim($file, '/'))) {
            $missing[] = (string)$source;
        }
    }

    $checks['Manifest de assets presente'] = true;
    $checks['Manifest de assets consistente'] = $missing === [];

    return [
        'ok' => $missing === [],
        'assets' => count($assets),
        'missing' => array_slice($missing, 0, 10),
    ];
}

function sv_health_public_secret_summary(?array $secretHealth): ?array
{
    if ($secretHealth === null) {
        return null;
    }

    $groups = [];
    foreach (($secretHealth['groups'] ?? []) as $group => $status) {
        if (!is_array($status)) {
            continue;
        }

        $groups[$group] = [
            'configured' => (bool)($status['configured'] ?? false),
            'ok' => (bool)($status['ok'] ?? false),
        ];
    }

    return [
        'ok' => (bool)($secretHealth['ok'] ?? false),
        'groups' => $groups,
    ];
}

$logsDir = $root . '/logs';
$tmpDir = sys_get_temp_dir();
$diskTotal = @disk_total_space($root) ?: 0;
$diskFree = @disk_free_space($root) ?: 0;
$diskUsedPct = $diskTotal > 0 ? round((($diskTotal - $diskFree) / $diskTotal) * 100, 2) : null;
$memoryLimit = sv_health_bytes((string)ini_get('memory_limit'));
$memoryUsage = memory_get_usage(true);

$checks = [
    'PHP ativo' => PHP_VERSION !== '',
    'Extensao JSON ativa' => extension_loaded('json'),
    'Diretorio logs gravavel' => sv_health_is_writable_dir($logsDir),
    'Diretorio temporario gravavel' => is_writable($tmpDir),
    'Espaco em disco acima de 10%' => $diskTotal === 0 ? true : (($diskFree / $diskTotal) >= 0.10),
    'Config versao presente' => is_file($root . '/config/shopvivaliz-version.php'),
    'Catalogo API presente' => is_file($root . '/api/catalog/products.php'),
    'GraphQL API presente' => is_file($root . '/api/graphql.php'),
    'Gamificacao API presente' => is_file($root . '/api/gamification/status.php'),
    'Health da fila acessivel' => is_file($root . '/core/queue/queue.php'),
];
// Rodada 8 (2026-08-19): 'Gamificacao pagina presente', 'Admin dashboard JS
// presente' e 'Monitor admin presente' foram removidos daqui -- eram
// is_file() puramente decorativos (so inflavam a contagem de "checks OK" sem
// verificar nenhum comportamento real; a presenca do arquivo no repo ja e
// garantida em tempo de deploy, nao em tempo de execucao). Os checks restantes
// acima cobrem features de fato consumidas por outros sistemas (fila,
// catalogo, GraphQL, gamificacao API). Ver R8-9 no relatorio da Rodada 8.

$queueSummary = sv_health_probe_queue($checks, $root);
$assetManifestHealth = sv_health_probe_asset_manifest($checks, $root);

$storageDisk = is_dir($root . '/storage') ? @disk_free_space($root . '/storage') : false;
$checks['Storage gravavel'] = sv_health_is_writable_dir($root . '/storage');
$checks['Storage com espaco minimo'] = $storageDisk === false ? true : $storageDisk > 10 * 1024 * 1024;

$secretHealth = null;
if (is_file($root . '/core/config/secret-health.php')) {
    try {
        require_once $root . '/core/config/secret-health.php';
        if (function_exists('sv_secret_health_report')) {
            $secretHealth = sv_secret_health_report();
            $checks['Secrets sem placeholder obvio'] = (bool)($secretHealth['ok'] ?? false);
        }
    } catch (Throwable $e) {
        $secretHealth = ['ok' => false, 'error' => 'secret_health_failed'];
        $checks['Secrets sem placeholder obvio'] = false;
    }
}

$healthScore = 0;
foreach ($checks as $value) {
    $healthScore += $value ? 1 : 0;
}
$healthRatio = count($checks) > 0 ? round(($healthScore / count($checks)) * 100, 2) : 0.0;

$ok = !in_array(false, $checks, true) && $healthRatio >= 85.0;
http_response_code($ok ? 200 : 207);

$svHealthPayload = [
    'ok' => $ok,
    'status' => $ok ? 'ok' : 'attention',
    'service' => 'shopvivaliz-admin-health',
    'generated_at' => date('c'),
    'health_score_percent' => $healthRatio,
];

if ($svHealthDetailed) {
    $svHealthPayload += [
        'php' => [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'memory_limit' => ini_get('memory_limit'),
            'memory_usage_bytes' => $memoryUsage,
            'memory_limit_bytes' => $memoryLimit,
        ],
        'server' => [
            'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'host' => $_SERVER['HTTP_HOST'] ?? 'unknown',
            'https' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ],
        'disk' => [
            'total_bytes' => $diskTotal,
            'free_bytes' => $diskFree,
            'used_percent' => $diskUsedPct,
        ],
        'paths' => [
            'root_ready' => is_dir($root),
            'logs_writable' => (bool)($checks['Diretorio logs gravavel'] ?? false),
            'temp_writable' => (bool)($checks['Diretorio temporario gravavel'] ?? false),
            'storage_writable' => (bool)($checks['Storage gravavel'] ?? false),
        ],
        'queue' => $queueSummary,
        'assets' => $assetManifestHealth,
        'secrets' => sv_health_public_secret_summary($secretHealth),
        'checks' => $checks,
    ];
}

echo json_encode($svHealthPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

if (PHP_SAPI === 'cli' && !$ok) {
    exit(1);
}
