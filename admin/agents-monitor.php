<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';

// Simple read-only monitoring panel for ShopVivaliz agents

$base = 'https://dev.shopvivaliz.com.br';
if (!empty($_SERVER['HTTP_HOST'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = $scheme . '://' . $_SERVER['HTTP_HOST'];
}

function fetch_json($url) {
    $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 8, 'ignore_errors' => true]]);
    $data = @file_get_contents($url, false, $context);
    if (!$data) return null;
    return json_decode($data, true);
}

$report = fetch_json($base . '/api/agent/autonomous-report.php');
$watchdog = fetch_json($base . '/api/agent/autonomous-watchdog.php');

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
