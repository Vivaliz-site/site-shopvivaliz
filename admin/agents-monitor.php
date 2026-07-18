<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';

// Simple read-only monitoring panel for ShopVivaliz agents

function am_php_json(string $relativeScript): array {
    $script = dirname(__DIR__) . '/' . ltrim($relativeScript, '/');
    if (!is_file($script)) {
        return [];
    }
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' 2>&1';
    $raw = @shell_exec($cmd);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

$report = am_php_json('/api/agent/autonomous-report.php');
$watchdog = am_php_json('/api/agent/autonomous-watchdog.php');

?><!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Agents Monitor</title>
    <style>
        body { font-family: Arial; background:#0f172a; color:#e2e8f0; padding:20px; }
        .card { background:#1e293b; padding:20px; margin-bottom:20px; border-radius:10px; }
        .ok { color:#22c55e; }
        .fail { color:#ef4444; }
        pre { background:#020617; padding:10px; overflow:auto; }
    </style>
</head>
<body>

<h1>ShopVivaliz - Agents Monitor</h1>

<div class="card">
<h2>Watchdog Status</h2>
<?php if ($watchdog): ?>
    <p>Status: <strong class="<?= !empty($watchdog['all_ok']) ? 'ok' : 'fail' ?>">
        <?= !empty($watchdog['all_ok']) ? 'OK' : 'ALERT' ?>
    </strong></p>
    <p>Última execução: <?= $watchdog['generated_at'] ?? '-' ?></p>
<?php else: ?>
    <p class="fail">Falha ao obter watchdog</p>
<?php endif; ?>
</div>

<div class="card">
<h2>Relatório Autônomo</h2>
<?php if ($report): ?>
    <p>Total produtos: <?= $report['catalog']['total'] ?? 0 ?></p>
    <p>Sem imagem: <?= $report['catalog']['no_image'] ?? 0 ?></p>
    <p>Preço zero: <?= $report['catalog']['zero_price'] ?? 0 ?></p>
    <p>ML conectado: <?= !empty($report['integrations']['ml_oauth_connected']) ? 'Sim' : 'Não' ?></p>
<?php else: ?>
    <p class="fail">Falha ao obter relatório</p>
<?php endif; ?>
</div>

<div class="card">
<h2>Raw Data</h2>
<pre><?= json_encode(['report'=>$report,'watchdog'=>$watchdog], JSON_PRETTY_PRINT) ?></pre>
</div>

</body>
</html>
