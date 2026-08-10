<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

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

    if (isset($queueSummary['total']) && (int)$queueSummary['total'] > 0) {
        $checks['Fila total operacional'] = ((int)($queueSummary['queued'] ?? 0) + (int)($queueSummary['running'] ?? 0) + (int)($queueSummary['done'] ?? 0) + (int)($queueSummary['failed'] ?? 0)) >= 0;
    }
    if (isset($queueSummary['stale'])) {
        $checks['Fila sem backlog travado'] = ((int)$queueSummary['stale']) === 0;
    }

    return $queueSummary;
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
    'Gamificacao pagina presente' => is_file($root . '/gamificacao.php'),
    'Admin dashboard JS presente' => is_file($root . '/js/admin-dashboard.js'),
    'Monitor admin presente' => is_file($root . '/admin/monitor/index.php'),
    'Health da fila acessivel' => is_file($root . '/core/queue/queue.php'),
];

$queueSummary = sv_health_probe_queue($checks, $root);

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

echo json_encode([
    'ok' => $ok,
    'status' => $ok ? 'ok' : 'attention',
    'service' => 'shopvivaliz-admin-health',
    'generated_at' => date('c'),
    'health_score_percent' => $healthRatio,
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
        'root' => $root,
        'total_bytes' => $diskTotal,
        'free_bytes' => $diskFree,
        'used_percent' => $diskUsedPct,
    ],
    'paths' => [
        'root' => $root,
        'logs' => $logsDir,
        'temp' => $tmpDir,
        'storage' => $root . '/storage',
    ],
    'queue' => $queueSummary,
    'secrets' => $secretHealth,
    'checks' => $checks,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
