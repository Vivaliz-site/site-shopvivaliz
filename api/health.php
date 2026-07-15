<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$root = dirname(__DIR__);

function sv_health_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (float)$value;
    switch ($unit) {
        case 'g': return (int)($number * 1024 * 1024 * 1024);
        case 'm': return (int)($number * 1024 * 1024);
        case 'k': return (int)($number * 1024);
        default: return (int)$number;
    }
}

function sv_health_is_writable_dir(string $path): bool
{
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }
    return is_dir($path) && is_writable($path);
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
];

$ok = !in_array(false, $checks, true);
http_response_code($ok ? 200 : 207);

echo json_encode([
    'ok' => $ok,
    'status' => $ok ? 'ok' : 'attention',
    'service' => 'shopvivaliz-admin-health',
    'generated_at' => date('c'),
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
    ],
    'checks' => $checks,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
