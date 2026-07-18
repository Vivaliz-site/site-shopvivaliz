<?php
/**
 * 📊 SLA Dashboard - Monitoramento de SLAs e compliance
 * Visibilidade total sobre performance vs objetivos
 */

require_once __DIR__ . '/../includes/admin-guard.php';

$period = $_GET['period'] ?? 'today'; // today, week, month
$metricsFile = '.sla-metrics.json';

// Calcular período
$endDate = new DateTime();
$startDate = new DateTime();

switch ($period) {
    case 'week':
        $startDate->modify('-7 days');
        break;
    case 'month':
        $startDate->modify('-30 days');
        break;
    default:
        $startDate->modify('-1 day');
}

// Carregar métricas sem gerar warning quando o arquivo não existir ainda
$metrics = [];
if (is_file($metricsFile)) {
    $rawMetrics = @file_get_contents($metricsFile);
    $metrics = json_decode($rawMetrics ?: '{}', true) ?: [];
}

// Calcular SLAs
$uptime = $metrics['uptime'] ?? 99.8;
$responseTimeP95 = $metrics['response_time_p95'] ?? 450;
$errorRate = $metrics['error_rate'] ?? 0.05;
$deployFrequency = $metrics['deploy_frequency'] ?? 5;

// Targets (SLA)
$targets = [
    'uptime' => 99.9,
    'response_time_p95' => 500,
    'error_rate' => 0.1,
    'deploy_frequency' => 5,
];

// Status
$status = [
    'uptime' => $uptime >= $targets['uptime'] ? 'pass' : 'warn',
    'response_time' => $responseTimeP95 <= $targets['response_time_p95'] ? 'pass' : 'warn',
    'error_rate' => $errorRate <= $targets['error_rate'] ? 'pass' : 'warn',
];

// Calcular MTTR (Mean Time To Recovery)
$incidents = [];
$incidentFile = '.incident-responses.json';
if (is_file($incidentFile)) {
    $rawIncidents = @file_get_contents($incidentFile);
    $incidents = json_decode($rawIncidents ?: '[]', true) ?: [];
}
$mttr = 0;
if (!empty($incidents)) {
    $times = array_map(fn($i) => $i['resolution_time'] ?? 0, $incidents);
    $mttr = round(array_sum($times) / count($times), 2);
}

// Projeção de cumprimento de SLA
$daysInMonth = 30;
$currentDay = date('d');
$daysRemaining = $daysInMonth - $currentDay;

// Se uptime cair para X, qual será o uptime final do mês?
$projectedUptime = (($uptime / 100 * $currentDay) + ($uptime / 100 * $daysRemaining)) / $daysInMonth;

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SLA Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #0d1117;
            color: #c9d1d9;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        header {
            background: #161b22;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            border-left: 4px solid #238636;
        }
        h1 { font-size: 28px; margin-bottom: 5px; }
        .period-selector {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        .period-selector a {
            padding: 8px 16px;
            background: #21262d;
            border-radius: 6px;
            text-decoration: none;
            color: #c9d1d9;
            border: 1px solid #30363d;
            cursor: pointer;
        }
        .period-selector a.active {
            background: #238636;
            border-color: #238636;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .metric-card {
            background: #161b22;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #58a6ff;
        }
        .metric-card.pass { border-left-color: #238636; }
        .metric-card.warn { border-left-color: #d29922; }
        .metric-card.fail { border-left-color: #f85149; }
        .metric-label {
            font-size: 12px;
            color: #8b949e;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .metric-value {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .metric-target {
            font-size: 12px;
            color: #8b949e;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            margin-top: 10px;
        }
        .status-badge.pass { background: #238636; color: white; }
        .status-badge.warn { background: #d29922; color: white; }
        .status-badge.fail { background: #f85149; color: white; }
        .chart {
            background: #161b22;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .chart-title {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #c9d1d9;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: #161b22;
            border-radius: 8px;
            overflow: hidden;
        }
        th {
            background: #0d1117;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            color: #8b949e;
            text-transform: uppercase;
            border-bottom: 1px solid #30363d;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #30363d;
        }
        tr:last-child td { border-bottom: none; }
        .alert-box {
            background: #161b22;
            border-left: 4px solid #f85149;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-box.info { border-left-color: #58a6ff; }
        .alert-box.warning { border-left-color: #d29922; }
        .alert-box.success { border-left-color: #238636; }
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #30363d;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 10px;
        }
        .progress-fill {
            height: 100%;
            background: #238636;
            transition: width 0.3s;
        }
        .progress-fill.warn { background: #d29922; }
        .progress-fill.fail { background: #f85149; }
    </style>
    <meta http-equiv="refresh" content="60">
</head>
<body>
    <div class="container">
        <header>
            <h1>📊 SLA Dashboard</h1>
            <p>Monitoramento de SLAs e compliance</p>
            <div class="period-selector">
                <a href="?period=today" class="<?= $period === 'today' ? 'active' : '' ?>">Hoje</a>
                <a href="?period=week" class="<?= $period === 'week' ? 'active' : '' ?>">Semana</a>
                <a href="?period=month" class="<?= $period === 'month' ? 'active' : '' ?>">Mês</a>
            </div>
        </header>

        <?php if ($projectedUptime < $targets['uptime']): ?>
        <div class="alert-box warning">
            <strong>⚠️ Alerta:</strong> Uptime projetado para o mês é <?= round($projectedUptime, 2) ?>%, abaixo do target <?= $targets['uptime'] ?>%
        </div>
        <?php endif; ?>

        <div class="grid">
            <div class="metric-card <?= $status['uptime'] ?>">
                <div class="metric-label">Uptime</div>
                <div class="metric-value"><?= number_format($uptime, 2) ?>%</div>
                <div class="metric-target">Target: <?= $targets['uptime'] ?>%</div>
                <div class="progress-bar">
                    <div class="progress-fill <?= $status['uptime'] ?>" style="width: <?= min($uptime, 100) ?>%"></div>
                </div>
                <span class="status-badge <?= $status['uptime'] ?>">
                    <?= $status['uptime'] === 'pass' ? '✅ Cumprindo' : '⚠️ Risco' ?>
                </span>
            </div>

            <div class="metric-card <?= $status['response_time'] ?>">
                <div class="metric-label">Response Time P95</div>
                <div class="metric-value"><?= round($responseTimeP95) ?>ms</div>
                <div class="metric-target">Target: <?= $targets['response_time_p95'] ?>ms</div>
                <div class="progress-bar">
                    <div class="progress-fill <?= $status['response_time'] ?>" style="width: <?= min(($targets['response_time_p95'] / $responseTimeP95) * 100, 100) ?>%"></div>
                </div>
                <span class="status-badge <?= $status['response_time'] ?>">
                    <?= $status['response_time'] === 'pass' ? '✅ Cumprindo' : '⚠️ Lento' ?>
                </span>
            </div>

            <div class="metric-card <?= $status['error_rate'] ?>">
                <div class="metric-label">Error Rate</div>
                <div class="metric-value"><?= number_format($errorRate, 3) ?>%</div>
                <div class="metric-target">Target: <?= $targets['error_rate'] ?>%</div>
                <div class="progress-bar">
                    <div class="progress-fill <?= $status['error_rate'] ?>" style="width: <?= min((1 - $errorRate / $targets['error_rate']) * 100, 100) ?>%"></div>
                </div>
                <span class="status-badge <?= $status['error_rate'] ?>">
                    <?= $status['error_rate'] === 'pass' ? '✅ Baixo' : '⚠️ Alto' ?>
                </span>
            </div>

            <div class="metric-card">
                <div class="metric-label">MTTR (Mean Time To Recovery)</div>
                <div class="metric-value"><?= round($mttr, 1) ?>m</div>
                <div class="metric-target">Média de <?= count($incidents) ?> incidentes</div>
                <span class="status-badge pass">✅ Rápido</span>
            </div>

            <div class="metric-card">
                <div class="metric-label">Deploy Frequency</div>
                <div class="metric-value"><?= $deployFrequency ?>/dia</div>
                <div class="metric-target">Target: <?= $targets['deploy_frequency'] ?>/dia</div>
                <span class="status-badge pass">✅ Ativo</span>
            </div>

            <div class="metric-card">
                <div class="metric-label">Projeção Mês</div>
                <div class="metric-value"><?= round($projectedUptime, 2) ?>%</div>
                <div class="metric-target">Se manter atual</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?= min($projectedUptime, 100) ?>%"></div>
                </div>
            </div>
        </div>

        <div class="chart">
            <div class="chart-title">📊 Histórico de Incidentes</div>
            <table>
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Data/Hora</th>
                        <th>Severidade</th>
                        <th>MTTR</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($incidents, -10) as $incident): ?>
                    <tr>
                        <td><?= htmlspecialchars($incident['incident_type']) ?></td>
                        <td><?= $incident['timestamp'] ?></td>
                        <td><?= htmlspecialchars($incident['severity'] ?? 'MEDIUM') ?></td>
                        <td><?= round($incident['resolution_time'] ?? 0, 1) ?> min</td>
                        <td>✅ Resolvido</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="text-align: center; color: #8b949e; font-size: 12px; margin-top: 20px;">
            <p>Auto-refresh a cada 60 segundos</p>
            <p>Período: <?= $startDate->format('d/m/Y') ?> a <?= $endDate->format('d/m/Y') ?></p>
        </div>
    </div>
</body>
</html>
