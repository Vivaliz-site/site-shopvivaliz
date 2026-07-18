<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';

// Simple read-only monitoring panel for ShopVivaliz agents

function am_json_file(string $relativePath): array {
    $path = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
    if (!is_file($path)) {
        return [];
    }
    $raw = (string)@file_get_contents($path);
    if (trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

$report = am_json_file('/logs/autonomous-cycle-report.json');
$watchdog = am_json_file('/logs/autonomous-hourly-guardian.json');

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
    <p>Status: <strong class="<?= !empty($watchdog['decision']['idle']) || !empty($watchdog['decision']['no_pending']) ? 'ok' : 'fail' ?>">
        <?= !empty($watchdog['decision']['idle']) || !empty($watchdog['decision']['no_pending']) ? 'OK' : 'ALERT' ?>
    </strong></p>
    <p>Última execução: <?= $watchdog['generated_at'] ?? ($watchdog['report_generated_at'] ?? '-') ?></p>
<?php else: ?>
    <p>Sem snapshot local do watchdog.</p>
<?php endif; ?>
</div>

<div class="card">
<h2>Relatório Autônomo</h2>
<?php if ($report): ?>
    <p>Total produtos: <?= $report['catalog']['total'] ?? ($report['backlog_snapshot']['pending'] ?? 0) ?></p>
    <p>Sem imagem: <?= $report['catalog']['no_image'] ?? 0 ?></p>
    <p>Preço zero: <?= $report['catalog']['zero_price'] ?? 0 ?></p>
    <p>ML conectado: <?= !empty($report['integrations']['ml_oauth_connected']) ? 'Sim' : 'Não' ?></p>
<?php else: ?>
    <p>Sem snapshot local do relatório.</p>
<?php endif; ?>
</div>

<div class="card">
<h2>Raw Data</h2>
<pre><?= json_encode(['report'=>$report,'watchdog'=>$watchdog], JSON_PRETTY_PRINT) ?></pre>
</div>

</body>
</html>
