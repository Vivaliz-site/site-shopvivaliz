<?php
/**
 * Painel de Auditoria - ShopVivaliz
 * Consolida sinais locais de segurança, execução e mudanças recentes.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-guard.php';
require_once dirname(__DIR__) . '/config/bootstrap-env.php';

$baseDir = dirname(__DIR__);

$readJson = static function (string $relativePath) use ($baseDir): array {
    $path = $baseDir . '/' . ltrim($relativePath, '/');
    if (!is_file($path)) {
        return [];
    }

    $raw = trim((string)@file_get_contents($path));
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
};

$readJsonl = static function (string $relativePath, int $limit = 200) use ($baseDir): array {
    $path = $baseDir . '/' . ltrim($relativePath, '/');
    if (!is_file($path)) {
        return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $items = [];
    foreach (array_slice($lines, -$limit) as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $items[] = $decoded;
        }
    }

    return $items;
};

$report = $readJson('/logs/autonomous-cycle-report.json');
$watchdog = $readJson('/logs/autonomous-hourly-guardian.json');
$events = $readJsonl('/logs/agent-execution-steps.jsonl', 120);
$cycleEvents = $readJsonl('/logs/autonomous-cycle-events.jsonl', 120);

$backlog = is_array($report['backlog_snapshot'] ?? null) ? $report['backlog_snapshot'] : [];
$automation = is_array($report['auto_audit'] ?? null) ? $report['auto_audit'] : [];
$decision = is_array($watchdog['decision'] ?? null) ? $watchdog['decision'] : [];

$healthStatus = strtoupper((string)($automation['status'] ?? ($decision['idle'] ?? false ? 'HEALTHY' : 'DEGRADED')));
$healthLabel = in_array($healthStatus, ['HEALTHY', 'OK'], true) ? 'Saudável' : 'Atenção';
$healthClass = in_array($healthStatus, ['HEALTHY', 'OK'], true) ? 'badge-low' : 'badge-medium';

$alerts = [];
foreach ((array)($automation['errors'] ?? []) as $item) {
    $alerts[] = [
        'id' => count($alerts) + 1,
        'timestamp' => (string)($report['generated_at'] ?? $watchdog['generated_at'] ?? date('c')),
        'severity' => 'high',
        'type' => (string)$item,
        'user_ip' => 'system',
        'status' => 'investigating',
    ];
}

foreach ((array)($automation['warnings'] ?? []) as $item) {
    $alerts[] = [
        'id' => count($alerts) + 1,
        'timestamp' => (string)($report['generated_at'] ?? $watchdog['generated_at'] ?? date('c')),
        'severity' => 'medium',
        'type' => (string)$item,
        'user_ip' => 'system',
        'status' => 'acknowledged',
    ];
}

foreach (($report['phase_summary']['phases'] ?? []) as $phase) {
    foreach (($phase['tasks'] ?? []) as $task) {
        $status = (string)($task['status'] ?? '');
        if (!str_starts_with($status, 'blocked')) {
            continue;
        }

        $alerts[] = [
            'id' => count($alerts) + 1,
            'timestamp' => (string)($task['selected_at'] ?? $task['created_at'] ?? $report['generated_at'] ?? date('c')),
            'severity' => 'medium',
            'type' => (string)($task['title'] ?? $task['id'] ?? 'Tarefa bloqueada'),
            'user_ip' => $status,
            'status' => 'acknowledged',
        ];
    }
}

$alerts = array_slice($alerts, 0, 8);

$recentChanges = [];
foreach (array_slice($events, -8) as $event) {
    $recentChanges[] = [
        'timestamp' => (string)($event['timestamp'] ?? $event['created_at'] ?? $report['generated_at'] ?? date('c')),
        'type' => strtoupper((string)($event['event'] ?? $event['action'] ?? 'EVENT')),
        'table' => (string)($event['source'] ?? $event['module'] ?? 'logs'),
        'user' => (string)($event['actor'] ?? $event['agent'] ?? 'system'),
        'status' => !empty($event['ok']) ? 'success' : 'warning',
    ];
}

if ($recentChanges === []) {
    foreach (array_slice($cycleEvents, -8) as $event) {
        $recentChanges[] = [
            'timestamp' => (string)($event['timestamp'] ?? $report['generated_at'] ?? date('c')),
            'type' => strtoupper((string)($event['event'] ?? $event['step'] ?? 'CYCLE')),
            'table' => (string)($event['source'] ?? 'autonomy'),
            'user' => (string)($event['agent'] ?? 'system'),
            'status' => !empty($event['ok']) ? 'success' : 'warning',
        ];
    }
}

if ($recentChanges === []) {
    $recentChanges[] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'type' => 'SNAPSHOT',
        'table' => 'logs',
        'user' => 'system',
        'status' => 'warning',
    ];
}

$totalEvents24h = count($events) + count($cycleEvents);
$totalAccessLogs = (int)($report['backlog_snapshot']['pending'] ?? 0) + (int)($report['backlog_snapshot']['in_progress'] ?? 0);
$failedLogins = count(array_filter($alerts, static fn(array $alert): bool => ($alert['severity'] ?? '') === 'high'));
$sensitiveEvents = count(array_filter($recentChanges, static fn(array $change): bool => ($change['status'] ?? '') !== 'success'));
$latestAlert = $alerts[0] ?? null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel de Auditoria - ShopVivaliz</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #e2e8f0;
            line-height: 1.6;
            min-height: 100vh;
        }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 30px; padding: 20px;
            background: rgba(30, 41, 59, 0.5);
            border-radius: 8px; border: 1px solid rgba(148, 163, 184, 0.1);
        }
        h1 { font-size: 28px; color: #f1f5f9; }
        .header-info { font-size: 12px; color: #94a3b8; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px; margin-bottom: 30px;
        }
        .card {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(148, 163, 184, 0.1);
            border-radius: 8px; padding: 20px; backdrop-filter: blur(10px);
        }
        .card-title { font-size: 12px; color: #94a3b8; text-transform: uppercase; margin-bottom: 10px; letter-spacing: 1px; }
        .card-value { font-size: 32px; font-weight: bold; color: #f1f5f9; }
        .card-subtitle { font-size: 12px; color: #64748b; margin-top: 10px; }
        .badge {
            display: inline-block; padding: 4px 12px; border-radius: 999px;
            font-size: 11px; font-weight: 600; text-transform: uppercase; margin-top: 10px;
        }
        .badge-high { background: rgba(239, 68, 68, 0.2); color: #fca5a5; }
        .badge-medium { background: rgba(251, 146, 60, 0.2); color: #fed7aa; }
        .badge-low { background: rgba(34, 197, 94, 0.2); color: #86efac; }
        .section { margin-bottom: 30px; }
        .section-title {
            font-size: 18px; color: #f1f5f9; margin-bottom: 15px;
            display: flex; align-items: center; gap: 10px;
        }
        .section-title::before {
            content: ''; width: 4px; height: 20px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border-radius: 2px;
        }
        table {
            width: 100%; border-collapse: collapse;
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(148, 163, 184, 0.1);
            border-radius: 8px; overflow: hidden;
        }
        thead { background: rgba(15, 23, 42, 0.8); }
        th {
            padding: 12px 16px; text-align: left; font-size: 12px;
            color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
        }
        td { padding: 12px 16px; border-bottom: 1px solid rgba(148, 163, 184, 0.05); }
        tr:hover { background: rgba(148, 163, 184, 0.05); }
        .status-badge {
            padding: 4px 12px; border-radius: 4px; font-size: 11px;
            font-weight: 600; text-transform: uppercase;
        }
        .status-success { background: rgba(34, 197, 94, 0.2); color: #86efac; }
        .status-failure { background: rgba(239, 68, 68, 0.2); color: #fca5a5; }
        .status-investigating { background: rgba(251, 146, 60, 0.2); color: #fed7aa; }
        .status-resolved { background: rgba(59, 130, 246, 0.2); color: #93c5fd; }
        .timestamp { font-size: 12px; color: #64748b; font-family: 'Courier New', monospace; }
        .alert-box {
            background: rgba(59, 130, 246, 0.1);
            border-left: 4px solid #3b82f6;
            padding: 15px; border-radius: 4px; margin-bottom: 10px;
        }
        .alert-box.warning { background: rgba(251, 146, 60, 0.1); border-left-color: #f97316; }
        .alert-box.danger { background: rgba(239, 68, 68, 0.1); border-left-color: #ef4444; }
        .alert-title { font-weight: 600; color: #f1f5f9; margin-bottom: 4px; }
        .alert-text { font-size: 13px; color: #cbd5e1; }
        .controls { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        button {
            padding: 8px 16px; background: rgba(59, 130, 246, 0.2); color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 4px;
            cursor: pointer; font-size: 12px; font-weight: 600; transition: all 0.2s;
        }
        button:hover { background: rgba(59, 130, 246, 0.3); border-color: rgba(59, 130, 246, 0.5); }
        .refresh-time {
            text-align: right; font-size: 11px; color: #64748b;
            margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(148, 163, 184, 0.1);
        }
        @media (max-width: 768px) {
            .grid { grid-template-columns: 1fr; }
            header { flex-direction: column; gap: 15px; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div>
                <h1>🔐 Painel de Auditoria</h1>
                <div class="header-info">ShopVivaliz - Segurança e Conformidade</div>
            </div>
            <div class="header-info">Última atualização: <?= date('d/m/Y H:i:s') ?></div>
        </header>

        <?php if ($latestAlert !== null): ?>
            <div class="alert-box <?= ($latestAlert['severity'] ?? '') === 'high' ? 'danger' : 'warning' ?>">
                <div class="alert-title">Último sinal detectado</div>
                <div class="alert-text">
                    <?= htmlspecialchars((string)($latestAlert['type'] ?? 'Evento')) ?>
                    - <?= htmlspecialchars((string)($latestAlert['status'] ?? 'observando')) ?>
                    em <?= htmlspecialchars((string)($latestAlert['timestamp'] ?? date('Y-m-d H:i:s'))) ?>
                </div>
            </div>
        <?php else: ?>
            <div class="alert-box">
                <div class="alert-title">Sem alerta crítico recente</div>
                <div class="alert-text">Os snapshots locais não mostram incidentes abertos neste momento.</div>
            </div>
        <?php endif; ?>

        <div class="grid">
            <div class="card">
                <div class="card-title">Eventos recentes</div>
                <div class="card-value"><?= number_format($totalEvents24h) ?></div>
                <div class="card-subtitle">Entradas de execução e ciclo</div>
            </div>
            <div class="card">
                <div class="card-title">Fila observada</div>
                <div class="card-value"><?= number_format($totalAccessLogs) ?></div>
                <div class="card-subtitle">Pendências + em progresso</div>
            </div>
            <div class="card">
                <div class="card-title">Alertas ativos</div>
                <div class="card-value"><?= number_format(count($alerts)) ?></div>
                <div class="badge <?= $healthClass ?>"><?= $healthLabel ?></div>
            </div>
            <div class="card">
                <div class="card-title">Mudanças sensíveis</div>
                <div class="card-value"><?= number_format($sensitiveEvents) ?></div>
                <div class="card-subtitle">Sinais a revisar</div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Alertas de Segurança</div>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tipo de Alerta</th>
                        <th>Severidade</th>
                        <th>IP/Origem</th>
                        <th>Timestamp</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($alerts as $alert): ?>
                    <tr>
                        <td>#<?= (int)$alert['id'] ?></td>
                        <td><?= htmlspecialchars((string)$alert['type']) ?></td>
                        <td>
                            <span class="badge badge-<?= strtolower((string)$alert['severity']) ?>">
                                <?= ucfirst((string)$alert['severity']) ?>
                            </span>
                        </td>
                        <td class="timestamp"><?= htmlspecialchars((string)$alert['user_ip']) ?></td>
                        <td class="timestamp"><?= htmlspecialchars((string)$alert['timestamp']) ?></td>
                        <td>
                            <span class="status-badge status-<?= strtolower((string)$alert['status']) ?>">
                                <?= ucfirst(str_replace('_', ' ', (string)$alert['status'])) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <div class="section-title">Mudanças Recentes no Banco</div>
            <table>
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Tipo de Evento</th>
                        <th>Fonte</th>
                        <th>Usuário</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentChanges as $change): ?>
                    <tr>
                        <td class="timestamp"><?= htmlspecialchars((string)$change['timestamp']) ?></td>
                        <td><code><?= htmlspecialchars((string)$change['type']) ?></code></td>
                        <td><?= htmlspecialchars((string)$change['table']) ?></td>
                        <td><?= htmlspecialchars((string)$change['user']) ?></td>
                        <td>
                            <span class="status-badge status-<?= strtolower((string)$change['status']) ?>">
                                <?= ucfirst((string)$change['status']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="controls">
            <button type="button">📥 Exportar Logs (CSV)</button>
            <button type="button">🔄 Atualizar Agora</button>
            <button type="button">⚙️ Configurações</button>
        </div>

        <div class="refresh-time">
            <?= $healthStatus ?> | Política de retenção: 365 dias
        </div>
    </div>

    <script>
        setTimeout(() => {
            window.location.reload();
        }, 60000);
    </script>
</body>
</html>
